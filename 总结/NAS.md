btrfs
	https://blog.csdn.net/zhuzongpeng/article/details/127115533
	COW + B-tree
	Subvolume（子卷）与 Snapshot（快照）
	send/receive：增量备份/复制  本地/异地备份
	校验与 scrub：数据完整性
	透明压缩
	快照回滚（防误删/勒索） + 低成本增量备份（send/receive） + 校验自愈（可靠性） + 压缩（空间）

btrfs 快照属于文件系统语义：以 subvolume 为单位，快照是元数据事务里的 root 切换，天然能做 send/receive 增量复制；代价是 COW 对随机写更敏感。dm-thin 快照属于块语义：对任何上层都适用，粒度按 chunk 做映射与 COW，必须靠上层 freeze/checkpoint 来拿到应用一致性，同时要重点关注 thin-pool data/metadata 水位和快照数量对性能与可靠性的影响。

在可靠性与多盘支持方面，ZFS更成熟，btrfs的RAID5/6不稳定
ZFS比较独立，有一套完整缓存/日志体系；btrfs在内核主线中，与其他模块兼容性更好

btrfs 的映射是“文件/extent 级”的，而 dm 是“块设备扇区级”的
btrfs 不是 device-mapper，它不会去复用 dm 的 target 框架来做映射。btrfs 的“卷管理能力”是文件系统内部实现的：通过 extent/chunk/block-group 和不同的多设备 profile 来完成数据放置、镜像/条带、快照等。
但在产品上两者经常叠加：比如用 dm-crypt 做加密、dm-multipath 做多路径，上面再跑 btrfs；这时候 btrfs 只把 dm 设备当作普通块设备使用。

OpenZFS 在 Linux 上是外部内核模块（zfs.ko），在内核里实现文件系统接口（ZPL），同时它内部自带存储池与 vdev 层，自己实现 mirror/RAIDZ 这类“卷管理+RAID”逻辑，而不是依赖 md/dm。文件在盘上被表示为 DMU 对象：每个对象由 dnode 描述，数据通过 block pointer 树引用到实际块，并用 COW 事务化更新。

dm-mpath
	负载均衡，根据IO队列长度选择路径以减少IO延迟

nfs遇到的问题
	客户端的一个进程一直写打开着一个文件，这个文件的内容在服务端被修改后，这个客户端的其他进程查看不到修改后的数据
