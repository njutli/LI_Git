```
cpolar
http://192.168.0.103:9200/
```

升级htags版本

lilingfeng3@outlook.com
gyesLLF1026...

互联网场景(spark/clickhouse/redis/mysql等)

文件系统
	VFS
		基本操作 mount/umount open/close read/write
		基本数据结构 superblock dentry inode file
		DIO/DAX/buffer IO(后台回写) bdi wb
	磁盘文件系统
		ext4/xfs
			磁盘结构 日志
		squashfs
			基本原理
	网络文件系统
		nfs
			基本流程 deleg(数据一致性，访问冲突处理)
			nfs 数据回写？bdi？super_setup_bdi_name nfs close-to-open
		nfsd
			启动流程，请求处理流程
	其他文件系统
		erofs

通用块层
	IO下发流程
	IO调度器
	rq-qos(iocost)
	IO拆分与合并

dm层
	dm-linear
	dm-snapshot
	dm-thinpool

驱动层
	SCSI
	NVME(namespace)

其他
	io_uring

磁电盘文件系统
	等开源后更新简历？

虚拟化相关
	docker kata qemu

客户端写：
1、数据写入
先调 nfs_file_write (write_iter回调)，数据写入buffer
2、数据同步
2.1 通过 nfs_commit_inode (fsync等操作触发)，发送 NFSPROC4_CLNT_COMMIT 请求，服务端通过 nfsd4_commit 处理commit请求，如果nfsd配置了sync，那么数据就直接落盘
2.2 通过 nfs_writepages (后台回写等操作触发)， 发送 NFSPROC4_CLNT_WRITE 请求



NFSD 需求
	重点问题 ———— 社区补丁分析


https://lore.kernel.org/all/tencent_6A84D2E177043C91217A1CF6@qq.com/

https://www.cpolar.com/blog/ubuntu-users-install-cpolar


leecode 滑动窗口问题 ———— 刷题
编译链接？ ———— 程序员的自我修养

raid基础？

erofs

f2fs

bpf map

GPU视频

虚拟化软件栈

内存基础

调度基础

wsl配置linux虚拟机启动qemu虚拟机调试内核流程

以nfsd为例分析slab缓存回收机制(shrinker)

zone设备 dm-zone

zoned storage

https://zonedstorage.io/docs/introduction

hungtask/softlockup/rcu原理

nfs挂载流程

不同nfs版本的差别
samba

dpdk/spdk
