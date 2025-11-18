https://blog.csdn.net/gatieme/category_6393814.html

1. ZONE_DMA、ZONE_NORMAL、ZONE_HIGHMEM 的物理地址范围是固定的吗？<br>
答： 是的，这些区域的物理地址范围在系统启动时就固定了（如 ZONE_DMA: 0-16MB, ZONE_NORMAL: 16MB-896MB, ZONE_HIGHMEM: 896MB以上），但这些划分是为了兼容硬件限制和内核地址空间映射，64位系统通常不需要 ZONE_HIGHMEM。

2. 为什么 ZONE_NORMAL 只有 896MB？为什么不能更大？<br>
答： 因为 32位 Linux 内核需要在自己的 1GB 虚拟地址空间（0xC0000000-0xFFFFFFFF）中直接映射物理内存，除去内核代码、数据结构、vmalloc 等预留空间后，只剩 896MB 可用于直接映射物理内存。

3. 那内核态程序是不是也只能使用 1GB 的虚拟地址？<br>
答： 不是，内核可以通过 vmalloc、kmap 等机制访问超过 1GB 的物理内存，但直接映射（线性映射）确实只有约 896MB，访问高端内存需要临时建立映射。

4. 为什么 0-16MB 是 ZONE_DMA？<br>
答： 因为古老的 ISA 总线 DMA 控制器只能访问 24位地址（0-16MB），为了兼容这些老硬件，内核将这段内存单独划分为 ZONE_DMA 区域。

5. 现代硬件还需要 ZONE_DMA 吗？<br>
答： 现代硬件通常不需要，因为 PCI/PCIe 设备支持 32位甚至64位地址，但内核保留 ZONE_DMA 是为了向后兼容和支持某些嵌入式系统中的特殊硬件。

6. 用户态程序能访问 0xC0000000-0xFFFFFFFF 这段虚拟地址吗？<br>
答： 不能，虽然这段地址在页表中有映射，但页表项的 user 位为 0，CPU 硬件会阻止用户态（Ring 3）访问，尝试访问会触发段错误（SIGSEGV）。

7. 既然用户态不能访问 0xC0000000 以上的地址，那这段虚拟地址的定义划分有什么意义呢？<br>
答： 意义巨大！进程通过系统调用陷入内核时，只提升特权级（Ring 3 → Ring 0）而无需切换页表，内核可直接访问内核空间（0xC0000000+）和用户空间（0x00000000+），避免了页表切换和 TLB 刷新，使系统调用性能提升 5-10 倍。

8. 用户态进程通过 malloc 多次分配堆内存，每个堆内存对应的虚拟地址是不断增大的吗？<br>
答： 不一定，小块内存（< 128KB）使用 brk/sbrk 在堆区分配，地址通常递增，但 free 后会复用；大块内存（≥ 128KB）使用 mmap 分配，地址通常递减；具体模式还受内存复用和地址空间随机化（ASLR）影响。

9. 栈对应的物理内存是怎么分配的？<br>
答： 栈的虚拟地址空间在进程启动时预留（如 8MB），但物理内存采用按需分配（Demand Paging）：声明栈变量时只预留虚拟地址，第一次访问时触发缺页中断才分配物理页框，大大提高了内存利用率。

```
每个进程的虚拟地址空间（32位 Linux）：

0x00000000 ┌─────────────────────────────────┐
           │                                 │
           │      进程 A 的用户空间           │
           │    (代码、数据、堆、栈等)         │
           │                                 │
0xBFFFFFFF ├─────────────────────────────────┤
0xC0000000 ├═════════════════════════════════┤
           │                                 │
           │        内核代码和数据            │
           │      (所有进程共享相同映射)       │
           │                                 │
0xFFFFFFFF └─────────────────────────────────┘

每个进程的页表都包含：
- 低 3GB：进程独有的映射（每个进程不同）
- 高 1GB：内核映射（每个进程相同）

0xC0000000 ┌─────────────────────────────────┐
           │  直接映射区 (896MB)              │ 物理内存 0-896MB
           │  ZONE_NORMAL                    │ 线性映射：virt = phys + 0xC0000000
           │  __va() / __pa()                │
0xF7C00000 ├─────────────────────────────────┤ ← 第一个分界点
           │  vmalloc 区域 (120MB)           │ 动态映射
           │  非连续物理内存                  │ 可以映射到任意物理页
           │  vmalloc(), ioremap()           │ 每个虚拟页独立映射
0xFFBFE000 ├─────────────────────────────────┤ ← 第二个分界点
           │  持久内核映射 (PKMap, 4MB)       │ HIGHMEM 临时映射
           │  kmap() / kunmap()              │ 有限的映射槽
0xFFC00000 ├─────────────────────────────────┤
           │  固定映射区 (4MB)                │ 固定用途的映射
           │  FIX_KMAP_BEGIN 等              │ 编译时确定
0xFFFFFFFF └─────────────────────────────────┘

```
1. 用户空间（0x00000000 - 0xC0000000）的映射
```
每个进程独立、延迟映射、按需分页

时刻 T1: malloc(4096) 返回后
虚拟地址          页表项              物理内存
0x08000000 ──→ [Present=0]          (无)
               (页表项存在，但标记为不存在)

时刻 T2: 第一次访问 *ptr 时
虚拟地址          页表项              物理内存
0x08000000 ──→ [Page Fault!]  ──→  分配物理页
                                     ↓
时刻 T3: 缺页中断处理后
虚拟地址          页表项              物理内存
0x08000000 ──→ [Present=1] ────→  0x12345000
               [PFN=0x12345]
               [User=1, RW=1]
```
2. ZONE_NORMAL（低端内存）
```
// 物理内存：0 - 896MB (x86-32)
// 内核虚拟地址：0xC0000000 - 0xF7C00000
// 映射关系：简单的线性映射

// 虚拟地址 = 物理地址 + 0xC0000000
// 物理地址 = 虚拟地址 - 0xC0000000

// 内核可以直接访问，无需额外操作
void *kernel_addr = __va(phys_addr);  // 简单的加法！
phys_addr_t phys = __pa(kernel_addr); // 简单的减法！
```
3. ZONE_HIGHMEM（高端内存）
```
每个进程独立、延迟映射、按需分页

访问 0xF7C00000 - 0xFFFFFFFF 时：

1) 如果是 vmalloc 区域 (0xF7C00000 - 0xFF800000)：
   → 映射的是**任意物理页**（可能来自低端或高端内存）
   → 用于内核需要大块虚拟连续内存的场景（如驱动程序缓冲区）

2) 如果是 pkmap 区域 (0xFF800000 - 0xFFA00000)：
   → 映射的是**高端内存页**（物理地址 > 896MB）
   → 用于长期访问高端内存

3) 如果是临时映射区 (0xFFA00000 - 0xFFC00000)：
   → 映射的是**高端内存页**（物理地址 > 896MB）
   → 用于短暂访问高端内存

4) 如果是固定映射区 (0xFFC00000 - 0xFFFFFFFF)：
   → 映射的是**特殊用途的物理地址**
   → 如硬件寄存器、BIOS 数据等
```

低端内存只有800多M，是所有进程共享的吗，例如进程1访问0xC1000000和进程2访问0xC1000000是访问的同一块地址吗<br>
所有进程的内核空间（0xC0000000-0xFFFFFFFF）映射完全相同，包括 pkmap、kmap_atomic、vmalloc 区域，都在内核页表中，所有进程看到相同的映射


如果分配高端内存返回的虚拟地址是0xFFFFFFF0，而分配的内存大小是0x20，超出0xFFFFFFFF范围的内存怎么访问<br>
所有内核内存分配都是页对齐的（4KB），永久映射区、临时映射区都预留了足够空间






以上讨论都是基于32位系统，对于64位系统，内存布局是怎样的
```
// 理论上的64位地址空间
理论地址空间 = 2^64 = 16 EB (Exabytes)
            = 16,777,216 TB
            = 18,446,744,073,709,551,616 字节

// 但实际上：现代CPU只使用部分地址位

// x86-64 架构的实际使用：
物理地址位数：36-52位（取决于CPU型号）
虚拟地址位数：48位（标准）或57位（LA57扩展）

// 48位虚拟地址空间
实际虚拟地址空间 = 2^48 = 256 TB

// 关键概念：规范地址（Canonical Address）
// 48位地址的第47位必须扩展到高16位
// 
// 有效地址范围：
用户空间：0x0000000000000000 - 0x00007FFFFFFFFFFF (128 TB)
内核空间：0xFFFF800000000000 - 0xFFFFFFFFFFFFFFFF (128 TB)
//       ^^^^^^^^^^^^^^^^^                        ^
//       第47位=1，高16位全为1                    规范地址

// 无效地址（非规范地址）：
// 0x0000800000000000 - 0xFFFF7FFFFFFFFFFF
// 这个区域称为"规范地址空洞"（Canonical Hole）
// 访问会触发 #GP (General Protection Fault)


// Linux x86-64 内存布局（48位）

起始地址              结束地址                大小      区域名称
====================================================================================
0000000000000000 - 00007FFFFFFFFFFF (128 TB)  用户空间
                   
0000800000000000 - FFFF7FFFFFFFFFFF          规范地址空洞（非法）
                   
FFFF800000000000 - FFFF87FFFFFFFFFF (  8 TB)  保护空洞（Guard Hole）
FFFF880000000000 - FFFFC7FFFFFFFFFF ( 64 TB)  直接映射区（Direct Mapping）
FFFFC80000000000 - FFFFC8FFFFFFFFFF (  1 TB)  保护空洞
FFFFC90000000000 - FFFFE8FFFFFFFFFF ( 32 TB)  vmalloc/ioremap 区域
FFFFE90000000000 - FFFFE9FFFFFFFFFF (  1 TB)  保护空洞
FFFFEA0000000000 - FFFFEAFFFFFFFFFF (  1 TB)  虚拟内存映射区（mem_map）
FFFFEB0000000000 - FFFFEBFFFFFFFFFF (  1 TB)  保护空洞
FFFFEC0000000000 - FFFFFBFFFFFFFFFF ( 16 TB)  KASAN 影子内存（如果启用）
FFFFFC0000000000 - FFFFFDFFFFFFFFFF (  2 TB)  保护空洞
FFFFFE0000000000 - FFFFFE7FFFFFFFFF (512 GB)  cpu_entry_area 映射
FFFFFE8000000000 - FFFFFEFFFFFFFFFF (512 GB)  保护空洞
FFFFFF0000000000 - FFFFFF7FFFFFFFFF (512 GB)  LDT 映射区
FFFFFF8000000000 - FFFFFFEEFFFFFFFF (444 GB)  保护空洞
FFFFFFEF00000000 - FFFFFFFEFFFFFFFF ( 64 GB)  EFI 运行时服务映射
FFFFFFFF00000000 - FFFFFFFF7FFFFFFF (  2 GB)  保护空洞
FFFFFFFF80000000 - FFFFFFFF9FFFFFFF (512 MB)  内核代码段（.text）
FFFFFFFFA0000000 - FFFFFFFFFEFFFFFF (1.5 GB)  内核模块映射区
FFFFFFFFFF000000 - FFFFFFFFFF5FFFFF (  6 MB)  保护空洞
FFFFFFFFFF600000 - FFFFFFFFFF600FFF (  4 KB)  vsyscall 页（传统）
FFFFFFFFFF601000 - FFFFFFFFFFE00000 (  8 MB)  保护空洞
FFFFFFFFFFE00000 - FFFFFFFFFFFFFFFF (  2 MB)  固定映射区（Fixmap）
```

系统（System）
    └── 节点（Node/pg_data_t）
            ├── ZONE_DMA
            ├── ZONE_NORMAL
            └── ZONE_HIGHMEM
                    └── 页框（Page Frame/struct page）
                            └── 伙伴系统（Buddy System）
                                    ├── order-0 (1页)
                                    ├── order-1 (2页)
                                    ├── ...
                                    └── order-10 (1024页)








