问：
从内核代码或者数据结构角度讲，unshare后隔离环境内外对目录树的改动互不感知是怎么实现的

答：
你问到点子上了：**unshare(CLONE_NEWNS) 隔离的不是“文件系统内容”，而是“挂载树（mount tree）的视图与拓扑”**。
所以“目录树的改动互不感知”这句话，在内核语境里更准确是：**对挂载树的改动（mount/umount/bind/propagation）在不同 mount namespace 之间默认不互相可见**；但如果你是在同一个文件系统里 `mkdir/touch`，那改的是 inode/dirent，**只要两边还挂着同一个 superblock，当然都能看到**（你前面实验就是这个情况）。

下面从内核数据结构和关键路径把它讲清楚。

---

## 1) 关键对象：每个进程“看到的挂载树”从哪来？

进程有一条很关键的指针链：

```
task_struct
  -> nsproxy
      -> mnt_ns   (mount namespace)
```

* `nsproxy` 是一组 namespace 指针的聚合（pid/ns/net/user/mnt…）。
* `mnt_ns` 指向一个 `struct mnt_namespace`，它就是“这组进程共享的挂载树视图”。

路径解析（namei）在做 pathname walk 的时候，会用“当前进程所在的 mount namespace 的挂载树”来决定：

* `/` 是哪个挂载点
* `/mnt/sda` 穿越到哪个 mount
* 某个路径是否被上层 mount 覆盖（mount shadowing）

---

## 2) 挂载树怎么表示：`struct mount`（内核内部）是一棵树

内核里对外是 `struct vfsmount`，但挂载树的组织主要用内部的 `struct mount`（`vfsmount` 被它包含/关联）。这棵树的典型关系字段你可以这样理解：

* `mnt_parent`：父挂载
* `mnt_mountpoint`：挂在父挂载的哪个 dentry 上
* `mnt_child` / `mnt_mounts`：孩子链表（“这个 mount 点上又挂了哪些子 mount”）
* `mnt_ns`：它属于哪个 `mnt_namespace`（这一点非常关键）

所以“同一路径在不同 namespace 下解析到不同结果”，核心原因就是：**路径穿越 mount tree 时走的是当前 ns 的那棵 `struct mount` 树**。

---

## 3) `unshare(CLONE_NEWNS)` 做了啥：复制一份“挂载树结构”，不是复制文件系统

系统调用链大致是：

```
sys_unshare
  -> ksys_unshare
     -> unshare_nsproxy_namespaces(...)
        -> copy_mnt_ns(...)
```

`copy_mnt_ns()`（在 `fs/namespace.c`）干的事情可以概括成：

1. **分配一个新的 `struct mnt_namespace`**
2. **把当前 namespace 的挂载树“拷贝成另一棵树”**（拷的是 *mount 结构节点*）
3. 新树中的每个 `struct mount` 都会指向同一个底层 `super_block`（也就是同一个文件系统实例），只是树节点不同、挂载关系不同

这就是为什么：

* 你在新 namespace 里 `mount tmpfs /mnt`：只是在新树里插入/替换了一些 mount 节点 → 外面看不到
* 但你 `echo 123 > /mnt/sda/file`：这是写同一个 ext4 superblock 下的目录项/inode → 外面当然看到

一句话：**mount namespace 复制的是“目录树的挂载拓扑”，不是“目录树里的内容”。**

---

## 4) “互不感知”是如何保证的：操作只改本 namespace 的 mount tree

`mount()` / `umount()` 这些系统调用在内核里最终都会落到对 **`struct mount` 树的插入/删除/重挂载**：

* mount：创建新 `struct mount` 节点，attach 到某个父 mount 的某个挂载点上
* umount：从树上 detach，并做必要的 refcount/清理

关键点是：**这些 attach/detach 操作以 `current->nsproxy->mnt_ns` 这棵树为作用域**。
因为每个 namespace 拥有自己的一套 `struct mount` 节点，操作只会修改这一套节点和链表，自然不会影响另一个 namespace 的那套节点。

---

## 5) 你必须知道的坑：挂载传播（propagation）会让“互不感知”失效

很多发行版（尤其 systemd 环境）把 `/` 设成 **shared mount**。这时你即便 `unshare(CLONE_NEWNS)`，新旧 namespace 之间在某些 mount 操作上可能仍然会发生传播（看起来像“互相感知”）。

传播的实现也在 `struct mount` 里：它有“peer group / master-slave”关系（shared/slave/private），内核在做 mount/umount 时会根据这些关系，把事件传播到其他 namespace 的对应 mount 节点上。

这也是为什么容器 runtime 往往会在进入新 mount ns 后立刻做：

```bash
mount --make-rprivate /
# 等价内核语义：把 / 递归设为 MS_PRIVATE，切断传播
```

这样才保证容器内外对挂载变化真正隔离。

---

## 6) 把你的例子“翻译成内核语言”

你执行：

```bash
unshare --mount --fork /bin/bash
echo 123 > /mnt/sda/file
```

* `unshare`：给 bash 分配了新的 `mnt_namespace`，复制了 mount tree 节点
* 但 `/mnt/sda` 这个路径在新旧两棵树里，解析到的仍是同一个底层 ext4 挂载（同一个 superblock）
* `echo > file` 改的是 ext4 的 inode/dirent → 两边都能看到

如果你在 unshare 里对 `/mnt/sda` 再挂一层（比如 overlay 或 bind 到别处），你才会看到“目录树视图隔离”的效果。

---

### 你可以在面试里用的总结句

> `unshare(CLONE_NEWNS)` 让进程获得独立的 `mnt_namespace`，本质是复制一份 `struct mount` 挂载树结构；mount/umount 修改的是这棵树，因此默认不会影响其他 namespace。它不隔离文件系统内容，内容是否可见取决于两边是否仍引用同一 superblock。要避免 mount 事件传播，需要把 `/` 设为 rprivate。

如果你愿意，我还可以把 **“为什么很多系统里 unshare 后 mount 会影响外面”**用一个最小复现实验写出来（shared → private 的对照），并对应到内核传播路径（mount propagation）让你形成肌肉记忆。
