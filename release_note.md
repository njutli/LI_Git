https://kernelnewbies.org/LinuxVersions

# io_uring
## 6.18
1. mixed sized CQEs
最大收益：CQ ring 内存/缓存效率
如果你的应用只有一小部分请求需要 32B CQE（比如：主要是 read/write/recv/send，但偶尔穿插 uring_cmd passthrough 或取时间戳），以前必须 IORING_SETUP_CQE32，导致 CQ ring 常驻内存翻倍、cache/TLB 压力翻倍。现在可以让“多数完成”仍然 16B，只有少数用 32B。
https://lore.kernel.org/all/20250821141957.680570-1-axboe@kernel.dk/

2. uring_cmd: add multishot support
不用 multishot：每个 IO/事件都是一次“命令提交 + 完成回包”。
用 multishot：先建立一个长期“事件/请求流”，后续每个 IO/事件只需要“填一个你提供的 buffer + 发一个 CQE 通知”，CQE 上用 IORING_CQE_F_MORE 表示流还在。
https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?id=620a50c927004f5c9420a7ca9b1a55673dbf3941

# dm

# nfs
## 6.18
1. Initial client support for RWF_DONTCACHE flag in preadv2() and pwritev2()
完成读写后及时释放pagecache
