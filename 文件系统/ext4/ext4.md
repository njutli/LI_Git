# EXT4 讨论整理笔记（Markdown）

> 覆盖本次对话里与 ext4 相关的要点：磁盘结构、Meta Block Groups、路径解析与读写流程、目录索引、块映射方式（indirect vs extents）、unwritten/written、日志（JBD2）与崩溃语义、dcache/icache、fallocate/truncate、buffered I/O 的同步/异步特性。

---

## 1. EXT4 磁盘结构速览（On-disk layout）

从宏观到微观（不画图版）：

- **Superblock**：全局参数（块大小、每组块数/ inode 数、特性位、日志信息等）
- **BGDT/GDT（Block Group Descriptor Table）**：每个 block group 的“索引”
  - 每组的 block bitmap / inode bitmap / inode table 起始位置
  - 计数器（free blocks/inodes 等）
- **每个 Block Group 内部**（典型布局）
  - block bitmap
  - inode bitmap
  - inode table（inode on-disk 记录）
  - data blocks（目录块、普通文件数据、extent tree 叶子块等）

目录本身也是“文件”，目录项存放在目录文件的数据块中（线性或 HTree 组织）。

---

## 2. Meta Block Groups（META_BG）为何影响最大块组数

### 2.1 不开 META_BG：为何常见上限是 `2^21` 个 block group

传统布局里：在有备份 superblock 的 block group 中，通常会在备份 superblock 后面**备份整张 GDT**（包含全文件系统所有 block group 的描述符）。

整张 GDT 的大小随 block group 数线性增长：

- `GDT_bytes = group_count * desc_size`

而它必须能放进“某个备份组开头可用的连续空间”（数量级可近似看作一个 block group 的容量）：

- `group_count * desc_size <= blocks_per_group * block_size`

代入常见值（4K block、每组 128MB、desc_size=64B）：

- `blocks_per_group * block_size = 32768 * 4096 = 128MB`
- `group_count <= 128MB / 64B = 2^21`

所以经常看到“不开 META_BG 最多约 `2^21` 组”的说法。

> 注意：不是每个组都存整张 GDT，而是“有备份 superblock 的那些组”需要预留空间放备份 superblock +（旧玩法的）整张 GDT 备份；因此出现“GDT 太大塞不下”的版式上限。

### 2.2 开启 META_BG：为何能提升块组上限（常见说法到 `2^32`）

META_BG 的核心变化：不再要求“某些组里放**整张** GDT 备份”，而是把描述符按 **metablock group** 分段存放/备份。

以 4K block、64B 描述符为例：

- 1 个 4K block 可容纳 `4096/64 = 64` 个 group descriptor  
- 一个 metablock group 可以管理一段范围内的 group descriptors，并把“这一小段描述符”放在很少的块中（而不是整张 GDT）
- 从而打破“整张 GDT 必须塞进一个组”的限制

之后块组数上限通常由实现/格式字段宽度等决定，常见会提到 `2^32` 级别；有些资料也会从“48-bit 数据块号”推导到理论值 `2^33`（`2^48 / 2^15 = 2^33`，每组 2^15 blocks）。

---

## 3. 目录项存储：线性目录 vs HTree（hash tree，dir_index）

目录文件的数据块里存放变长目录项（简化理解：`name -> inode number`）。

### 3.1 线性目录（Linear directory）

- 目录项按顺序存放在目录文件的数据块中
- lookup：顺序扫描目录块，逐项比较名字
- 优点：简单，小目录很快
- 缺点：大目录查找 `O(n)`，扫描成本高

### 3.2 HTree / dir_index（hash tree）

- 对文件名计算 hash
- 通过根/索引结构定位到对应叶子块（bucket）
- 叶子块里依旧是普通目录项列表，但范围缩小
- lookup：`hash -> 定位叶子块 -> 叶子块内线性匹配（处理 hash 冲突）`
- 优点：大目录查找和插入更稳定（接近 `O(log m)` + 小范围扫描）
- 缺点：实现更复杂；hash 冲突时仍需比对真实名字；叶子块可能分裂（split）

---

## 4. 块映射索引：直接/间接索引 vs extent tree

ext4 支持两套把 “文件逻辑块号 -> 磁盘物理块号” 的映射方式。

### 4.1 直接/间接块指针（direct / single / double / triple indirect）

inode 内 `i_block`（概念 15 个指针槽）：
- 12 个 direct
- 1 个 single indirect：指向“指针块”（里面全是物理块号）
- 1 个 double indirect：两级指针块
- 1 个 triple indirect：三级指针块

特点：
- 小文件简单直接
- 大文件元数据膨胀（指针块数量巨大）
- 随机访问可能触发多层指针块读取

### 4.2 extent tree（现代默认）

extent 记录连续区间映射：
- `[logical_start, len] -> physical_start`

extent tree：
- 内部节点（idx）：按 logical_start 索引下一层节点块
- 叶子节点（extent）：存 extent 记录
- 查找：按逻辑块号逐层二分定位 -> 在叶子 extent 中计算物理块号

特点：
- 连续空间用少量 extent 表达，元数据大幅减少
- 树浅、扇出大，查找通常更快
- 支持 unwritten extent（见下一节），配合预分配/崩溃语义更好

---

## 5. unwritten vs written：用来做什么？何时转换？

### 5.1 目的（核心语义）
unwritten extent = **块已分配、元数据可指向，但逻辑内容必须视为 0**，直到真正写入完成才转为 written。

解决两类问题：
1. **防止泄露旧数据**：新分配块可能残留旧内容，不能让读者看到
2. **支持预分配/零化**：例如 `fallocate()` 预占空间，不必立即写满 0
3. **配合崩溃一致性**：避免“元数据已指向新块，但数据没写好”的窗口暴露垃圾

### 5.2 何时转换（unwritten -> written）
原则：**当且仅当对应数据写入成功完成后**。

常见触发：
- buffered write 的 writeback：数据 BIO 完成后转换
- direct I/O：I/O 完成后转换
- fallocate 预分配后，未来第一次真正写入到该范围时：被写到的部分转 written，其他仍 unwritten（可能发生 extent 拆分）

---

## 6. 从路径解析开始：读一个文件某一块数据的流程（buffered read）

> “读”视角：系统调用要把数据交给用户，因此缓存 miss 时通常要等待该页变为 uptodate。

1. **路径解析（namei）**
   - dcache 命中则直接走
   - miss：读取父目录（目录文件）的目录块（线性或 HTree）
   - 得到 inode number，`iget()` 获取 inode（icache miss 需读 inode table）
2. **进入读路径（generic_file_read_iter）**
   - 计算 offset -> page
   - page cache 命中且 uptodate：直接拷贝返回
   - miss：触发 readpage/readahead
3. **块映射（逻辑块 -> 物理块）**
   - extent tree 查找或 indirect 查找
4. **构造 bio 下发读 I/O**
   - submit 到块层 -> 驱动 -> 设备读入内存页
5. **I/O 完成**
   - page 标记 uptodate，唤醒等待者
   - 拷贝给用户返回

---

## 7. 从路径解析开始：写一个文件某一块数据的流程（buffered write）

> “写”视角：write() 语义通常只要求“数据被接收”，往往是写入 page cache 并标脏；真正落盘在 writeback/fsync。

1. **路径解析（同读）**，拿到目标 inode
2. **写入 page cache**
   - 找到对应 page（必要时先读旧页做 RMW）
   - copy 用户数据到 page
   - 标记 page dirty
3. **延迟分配（delalloc）常见**
   - 先不分配物理块，只记录“这个逻辑范围将来要落盘”
4. **writeback / fsync 触发真正落盘**
   - 需要时分配块（mballoc）并更新：
     - block bitmap / group desc
     - extent tree（常先建 unwritten extent）
     - inode（size/mtime/blocks 等）
   - 下发数据写 BIO 到设备
   - BIO 完成后：unwritten -> written（必要时）
   - journal 提交元数据事务（见下一节）

---

## 8. ext4 日志（JBD2）：概念与使用流程

### 8.1 基本概念
- **transaction**：把一组相关元数据更新作为原子单元提交
- **handle/credits**：修改元数据前预留 journal 空间
- **commit**：把事务写入 journal 并写 commit block，表示可重放
- **checkpoint**：把 journal 中已提交事务的元数据写回其 home 位置，释放日志空间
- **revoke**：避免重放覆盖新内容

### 8.2 data= 模式（影响“数据块”处理）
- `data=ordered`（默认常见）：数据不进 journal，但要求 **数据先落盘，再提交会使其可达的元数据事务**
- `data=writeback`：不保证数据写入顺序，崩溃后可能出现内容旧/乱（结构一致）
- `data=journal`：数据和元数据都进 journal，最强但写放大大

### 8.3 你总结的顺序“元数据内存→数据落盘→日志落盘→元数据落盘”如何更精确
对 `data=ordered + buffered write` 更准确的时序约束是：

- 数据/元数据通常先在内存变脏（write() 可能就返回）
- **writeback 时：先把相关数据块写到 home 并完成**
- **再提交 journal（把元数据写入 journal + commit block）**
- **之后再 checkpoint：元数据写回 home**

即：
`dirty in memory -> data write(home) -> journal commit(metadata) -> checkpoint(metadata home)`

---

## 9. 崩溃语义：如果“元数据未提交 journal，但数据已落盘”会怎样？

关键看这次写是否需要“元数据让数据变得可达”。

### 9.1 覆盖写已有块（映射不变）
- 数据写到了原位置
- 即使 inode 时间戳等元数据没 commit，文件仍指向该物理块
- **恢复后可能看到新数据**（取决于设备缓存/是否真持久化）
- 可能丢 mtime/ctime 等元数据更新

### 9.2 写导致新分配块 / 扩展 / 写洞（依赖元数据建立映射/i_size）
- 数据块可能已写到磁盘，但 extent/bitmap/i_size 等元数据未 commit
- **恢复后通常看不到这次写入**：因为没有提交的元数据不会被重放，数据不可达
- 可能出现两种后果：
  - bitmap 未落盘：块仍被认为空闲，未来可能被重用覆盖（“幽灵写入”）
  - bitmap 落盘但映射没提交：形成空间泄露（已分配但无人引用），fsck 才能回收

ordered 模式主要防的是反方向灾难：**元数据先提交指向新块，但数据未写好/块含旧内容**；配合 unwritten extent 可进一步避免泄露。

---

## 10. dentry cache 与 inode cache（分配、使用、回收）

### 10.1 dcache（dentry）
- 缓存 “父目录 dentry + name -> 子 dentry”
- 可能是正 dentry（绑定 inode）或负 dentry（表示不存在）
- 创建时机：路径解析 miss 时分配并调用 fs `lookup`
- 回收：
  - 引用计数归零后进入 dentry LRU
  - 内存压力/ drop_caches / shrinker 回收
- 失效：
  - rename/unlink 会更新相关 dentry 状态
  - 本地 ext4 通常可长期信任；网络 fs 有 revalidate

### 10.2 icache（inode）
- 缓存 inode 元数据与映射信息（extent root、权限、size、时间戳…）
- 创建时机：
  - lookup 得到 ino 后 `iget()`，cache miss 读 inode table
  - create 时分配新 inode 并初始化
- 回收：
  - 引用计数归零后可进入 inode LRU
  - 脏 inode 需先 writeback（或事务处理）后再可回收
- unlink 语义：
  - dentry 可变负，但若文件仍打开，inode 仍存活；最后引用释放时才真正回收块

> 关于“引用计数变 0 到进入 LRU 前是否有统一链表”：一般不会有额外“中间态全局链表”。统一管理主要依赖 hash（可查找）+ LRU（可回收）+ shrinker（回收机制），以及 per-superblock 的辅助链表用于遍历/写回等。

---

## 11. fallocate 与 truncate：ext4 的处理要点

### 11.1 fallocate（预分配/打洞/零化/移动）
总体：**主要改块映射（extent/bitmap），不一定改 i_size**，大量依赖 unwritten extent。

- `fallocate(mode=0)`：预分配空间，常以 **unwritten extent** 记录；可能扩展 i_size
- `FALLOC_FL_KEEP_SIZE`：预分配但不改变 i_size
- `FALLOC_FL_ZERO_RANGE`：逻辑上变 0，常通过 unwritten extent 实现（边界 partial block 可能实际清零）
- `FALLOC_FL_PUNCH_HOLE|KEEP_SIZE`：打洞释放块但不改 i_size（extent 裁剪/删除 + bitmap 释放）
- `FALLOC_FL_COLLAPSE_RANGE / INSERT_RANGE`：范围折叠/插洞（批量改映射，代价更大）

### 11.2 truncate / ftruncate（核心是改 i_size）
- **扩大（new > old）**
  - 通常只改 i_size（增长部分为 hole，读为 0）
  - 不一定立即分配块
- **缩小（new < old）**
  - 要释放尾部映射（extent 裁剪/删除、释放 bitmap）
  - 可能很大，跨多个事务：使用 **orphan inode 机制**保护崩溃后一致性
    - 先把 inode 加入 orphan list
    - 更新 i_size 并释放块（可能多次事务）
    - 完成后从 orphan list 删除
    - 崩溃后恢复会扫描 orphan list 继续清理，避免泄露

---

## 12. buffered I/O（page cache）里读写“同步/异步”的准确说法

- **buffered read**
  - cache hit：无 I/O，立即返回
  - cache miss：需要读盘把页变 uptodate，**读者线程通常等待**，因此表现“同步”
  - readahead 会异步读后续页，但当前所需页一般要等到可用
- **buffered write**
  - 常态：写入 page cache 标脏即可返回，I/O 由 writeback 异步下发
  - 但会同步阻塞的常见情形：
    - `O_SYNC / O_DSYNC` 或 `fsync/fdatasync`
    - 脏页过多导致 balance_dirty_pages 节流（写线程被迫回写/等待）
    - partial write 触发 RMW（先同步读旧页）
    - 块分配/元数据路径重（quota、journal credits、extent 分裂等）

---

## 13. 一句话“抓大纲”
- **目录**：小目录线性扫；大目录用 HTree 把查找缩小到少数块
- **块映射**：老的 indirect 像多级页表；extents 用“区间+树”大幅省元数据
- **unwritten**：分配了但读为 0，写成功后转 written，防泄露/助预分配/稳崩溃语义
- **日志**：commit 让元数据可重放；checkpoint 才把元数据写回家；ordered 关键约束是“数据先落盘再提交可达元数据”
- **缓存**：dcache 加速路径解析，icache 加速 inode 元数据；引用归零进入 LRU，由 shrinker 回收
- **fallocate/truncate**：前者主要改映射（常用 unwritten），后者主要改 i_size（缩小需释放并用 orphan 护航）
- **buffered I/O**：读 miss 需要等页读回；写通常先脏页后异步落盘，fsync/O_SYNC/节流等会让写阻塞

---
