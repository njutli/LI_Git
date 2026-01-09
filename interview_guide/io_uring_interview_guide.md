# io_uring

## 一、入门级：基本概念与背景

### Q1：什么是 io_uring？为什么引入它？
**A：**
io_uring 是 Linux 5.1 引入的异步 I/O 框架，由 Jens Axboe 设计，用于减少系统调用次数和用户态/内核态切换开销。相比传统 AIO（libaio），它性能更高、更灵活，能够支持广泛的异步操作类型。

---

### Q2：io_uring 与传统 read/write 系统调用的主要区别是什么？
**A：**
- 传统 read/write 是同步阻塞的系统调用；
- io_uring 采用共享环形缓冲区的异步提交/完成机制：
  - 用户进程提交请求至 Submission Queue（SQ）；
  - 内核完成后将结果写入 Completion Queue（CQ）；
  - 用户可异步获取结果，无需阻塞等待。

---

## 二、进阶级：架构与机制

### Q3：io_uring 的主要数据结构是什么？
**A：**
1. **SQ（Submission Queue）**：提交请求的环形队列。
2. **CQ（Completion Queue）**：返回完成事件的队列。
3. **SQE（Submission Queue Entry）**：一次 I/O 请求描述。
4. **CQE（Completion Queue Entry）**：I/O 完成的结果项。
5. **SQ索引数组**：保存SQE的索引，当前与SQE是一一对应关系。
---

### Q4：io_uring 如何减少系统调用开销？
**A：**
- 使用 `mmap` 映射共享 SQ/CQ 到用户空间；
- 通过单次 `io_uring_enter()` 批量提交/等待多个请求；
- 支持链式操作（linked SQEs）以减少上下文切换。

---

### Q5：io_uring 支持哪些类型的操作？
**A：**
支持文件、网络、超时、事件、设备命令等广泛操作：
- `readv/writev`, `recv/send`, `accept/connect`
- `fsync`, `poll`, `timeout`, `splice`, `openat`, `close`
- `uring_cmd` 扩展支持驱动层命令（如块设备）。

---

## 三、高级：性能优化与内核机制

### Q6：io_uring 的零拷贝机制（fixed buffer）是怎么实现的？
**A：**
- 用户通过 `IORING_REGISTER_BUFFERS` 注册内存缓冲区；
- I/O 直接引用这些缓冲区（`IOSQE_FIXED_BUF`），避免频繁 pin/unpin 和内存拷贝；
- 实现近似零拷贝的数据传输。

---

### Q7：io_uring 如何支持多线程并发？
**A：**
- SQ/CQ 基于无锁环形队列设计；
- 各线程可安全地并发提交；
- 内核通过 io-wq 线程池处理阻塞型操作；
- 非阻塞操作可在内核上下文中直接完成。

---

### Q8：io-wq 线程池的作用是什么？
**A：**
- 用于执行无法立即完成的阻塞操作；
- 动态调整线程数量；
- 可结合 `IORING_SETUP_SQPOLL` 或 `IORING_SETUP_IOPOLL` 控制行为；
- 避免应用线程因阻塞而降低并发性能。

---

## 四、深入：I/O 路径与内核交互

### Q9：DIO（Direct I/O）与 io_uring 的关系？
**A：**
- DIO 绕过页缓存，直接在用户缓冲区与设备间 DMA；
- io_uring 结合 DIO 可实现真正的用户态→设备异步路径；
- 适合数据库、KV 存储等低延迟场景。

---

### Q10：io_uring 与 AIO（libaio） 的关键改进对比

| 特性 | AIO | io_uring |
|------|------|-----------|
| 模型 | 部分异步（仅 O_DIRECT） | 真异步 |
| 系统调用 | 每次提交都 syscall | 批量提交，共享环 |
| 支持类型 | 仅文件 | 文件、socket、event 等 |
| 线程模型 | kthread | io-wq 线程池 |
| 性能 | 较差 | 高，接近 epoll + non-block |

---

## 五、专家级：优化与问题定位

### Q11：哪些场景 io_uring 反而性能不佳？
**A：**
- 小量 I/O 或低并发；
- 存储延迟高的 HDD；
- 使用页缓存命中率高的场景；
- 固定缓冲区使用不当导致队列拥塞。

---

### Q12：如何排查 io_uring 程序中 I/O 挂起？
**A：**
- 检查 CQ head/tail 是否更新；
- 确认 `IORING_ENTER_GETEVENTS` 调用逻辑；
- 检查 `IOSQE_IO_LINK` 链式请求是否中断；
- 确认 SQPOLL 线程是否退出；
- 调用 `/proc/<pid>/fdinfo` 及 `trace_event io_uring:*` 调试。

---

### Q13：如何用 io_uring 优化 Redis/MySQL？
**A：**
- 后台写日志使用 io_uring + fixed buffer；
- 顺序大块 I/O 使用 DIO；
- 开启 SQPOLL 减少 wakeup；
- 实现自定义多路复用（timeout/poll）；
- 每线程独立 ring，避免锁竞争。

---

## 六、附加加分题

### Q14：描述 io_uring read 的路径（以 ext4 + DIO 为例）
**A：**
1. 用户提交 SQE（op=READ）；
2. 内核从 SQ 获取请求；
3. 通过 `vfs_read_iter()` → `ext4_file_read_iter()`；
4. ext4 调用 `iomap_dio_rw()` → `submit_bio()`；
5. 块层发往驱动；
6. I/O 完成后 bio_end_io → CQE 返回用户态。

---

### Q15：io_uring 的未来发展方向
**A：**
- 统一异步 API（文件、网络、驱动）；
- 深度内核集成（块层原生 io_uring 支持）；
- eBPF 与 io_uring_cmd 融合；
- 用户态 I/O 框架内核化趋势（如 SPDK/DPDK）。

---

## 总结

| 层次 | 目标 | 评估内容 |
|------|------|-----------|
| 入门 | 理解 io_uring 概念 | 异步模型基础 |
| 进阶 | 理解结构与机制 | SQ/CQ 流程 |
| 高级 | 掌握并发与优化 | io-wq、poll 模式 |
| 深入 | 理解 I/O 路径 | 文件系统交互 |
| 专家 | 性能与定位 | 实际系统优化方案 |

# nfs客户端在打开文件的情况下，服务端修改文件后，客户端怎么知道缓存数据是旧的
如果有其他客户端打开文件，服务端会做delegation recall；
当前客户端通过getattr，重新open，或者缓存超时来判断当前缓存数据是旧的（Linux NFS 客户端对 inode 维护一组时间点/有效期）

# 内存拷贝的时候为什么要内存对齐，用户态通过系统调用传递给内核的数据为什么要有一次拷贝动作
> 为什么要对齐
1. 不对齐的地址拷贝可能使得本来一次就能全部拷贝的数据要分两次拷贝
2. 未对齐会导致多映射/多段，硬件散列表（scatter-gather）条目变多（NVME获取主机侧缓存数据？）
- 让 CPU/设备/IOMMU 用最省事的方式搬数据，很多时候是 DMA 的硬门槛

> 用户态数据为什么要拷贝
1. 安全考虑，确保数据内容在整个系统调用期间稳定
2. 生命周期考虑，异步IO可以在系统调用返回后，用户态释放内存的情况下继续执行IO操作（针对小数据或不方便 pin page的场景）
- 是内核在安全、生命周期、硬件限制之间做的折衷；零拷贝不是默认，而是满足一堆条件后才能拿到的奖励


# 介绍一下hungtask的原理
khungtaskd
1. 周期性扫描系统里的任务列表（所有进程/线程）
2. 对每个 task：
- 看它是不是处于“我关心的阻塞态”（D / killable 等）
- 看它有没有调度运行过
- 看它在这个状态里已经持续多久
如果长时间处于D状态且没有调度运行过，就打印警告

## 如果一个进程在R和D状态之间切换，每次khungtaskd扫描的时候刚好在D状态，也会触发hungtask吗
不会因为“每次扫描刚好看到 D”就触发；触发通常意味着：在超时时间内它没有被调度运行过（没进展），而不只是“那一刻在 D”。
task_struct 里面有调度次数的记录，可以根据这个记录确认进程是否调度过

`sysctl_hung_task_detect_count` 检查hungtask进程数


# 介绍一下soft lockup的原理
看门狗线程watchdog是由内核创建的percpu线程，创建后一直睡眠，然后等待htimer周期性的唤醒自己。被唤醒后watchdog线程就会去“喂狗”，即将当前时间戳写入到percpu变量watchdog_touch_ts中。
percpu变量watchdog_touch_ts的更新和softlockup的检测是两种方式：
1. watchdog_touch_ts更新
通过异步进程更新，如果当前CPU没有softlockup，就可以异步执行更新函数来更新 watchdog_touch_ts ，否则 watchdog_touch_ts 无法更新
2. softlockup检测
由于定时器是通过中断触发每个CPU执行percpu线程，因此即使发生了softlockup，也可以进行检查。如果发生了 softlockup ，前面无法异步更新 watchdog_touch_ts ，那么此时就检测到 softlockup ，否则检查通过


# 介绍一下hard lockup的原理
不能正常响应NMI中断
1. NMI能进来，但CPU不能处理
通过perf event检测
2. NMI不能进来
通过其他正常的CPU检测


# 介绍一下rcu的原理（rcu stall）
核心目标：让“读”几乎不加锁、非常快；把复杂度转移到“写/更新”那边。它适合那种“读多写少、读路径必须极快”的共享数据结构

1. 读者不阻塞：读者进入“RCU 读临界区”后，可以无锁地读指针、遍历链表。
2. 写者不就地改：更新不是在原对象上改，而是复制一份新对象，改新对象，再把全局指针一次性切过去（原子指针替换）。
3. 延迟回收旧对象：旧对象不能立刻 free，必须等一个**宽限期（grace period）**过去：确认“所有可能仍在读旧对象的读者都结束了”，再释放旧对象。

宽限期的定义：
> 从某个时刻开始，等到系统里 所有 CPU 都经历过至少一次“RCU 读临界区不可能继续持有旧引用”的状态，就算宽限期结束。

## rcu_read_lock禁止抢占后，会不会有中断导致 CPU 上下文切换？
会有中断，但中断本身通常不会导致“进程上下文切换”，真正的“进程上下文切换”要靠调度器 schedule() 发生。
rcu_read_lock() 禁的是抢占，不禁中断；中断会来，但一般不会导致 task 切换

## rcu_read_unlock() 后读者退出临界区；如果当前 CPU 之后卡住不切换，会不会阻塞写者释放资源？
写者等的是“所有读者都退出临界区/被确认不会再引用旧对象”，不是等某个 CPU 一定要发生一次 context switch，根据 __rcu_read_lock() 的不同实现分两种情况。
1. 只关抢占
有可能一直阻塞，因为此时写者判断读者是否退出临界区的依据是CPU有没有切换上下文

2. rcu_read_lock_nesting
执行 rcu_read_unlock() 后 rcu_read_lock_nesting 归零，对于写者而言读者已经退出了临界区，不阻塞写者释放资源

## 写者怎么判断读者是否离开了临界区
写者不直接“盯切换”；非抢占式靠调度器/idle/tick 报告 quiescent state，PREEMPT_RCU 则靠 per-task 的读锁计数/blocked readers 判断。

srcu 原理
https://blog.csdn.net/qq_39665253/article/details/153770913
SRCU不依赖CPU的调度状态，而是为每一个被保护的数据结构实例维护一个struct srcu_struct。这个结构体内部有一个小数组（通常是两个元素）的计数器和一个当前“活动”计数器的索引，根据使用待释放资源的进程数来判断是否可以释放

rcu stall 原理？



# 介绍一下dm-pcache


# 介绍一下samba



# 介绍一下blk-wbt原理


# 介绍一下活锁和死锁
1. 死锁
   多个线程/进程之间形成了循环等待：每个人都拿着一部分资源，同时等着别人手里的资源，于是永远等下去
2. 活锁
   线程没有阻塞，反而不断运行、不断重试、不断“礼让”，但因为策略/时序问题，总在互相干扰，导致系统整体没有进展。常见于：trylock + 失败就立刻释放/重试；冲突检测后双方都回滚并同时重试


