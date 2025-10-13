# summarize
## （一）互联网场景性能优化
### redis
```
内存数据库,用于实时性很强的场景
高性能缓存和中间态存储组件，承接大量短小、频繁的请求

为什么可以用io_uring提升性能？
1、单线程模型（io_uring实例初始化后作为一个fd由进程管理，也支持多进程共享？没研究过）
2、系统调用次数多是一个性能瓶颈（io_uring通过共享的ring buffer传递请求和执行结果，减少系统调用次数）

io_uring 通过减少系统调用、批量化提交与完成、内核态流水化及异步文件/网络 I/O，能显著降低开销与尾延迟，从而提升 Redis 在高并发小请求与持久化压力下的整体性能。
（关键还是减少系统调用，减少普通的read/write系统调用，也减少原本epoll查询结果的系统调用，使用io_uring的话可以直接检查CQ，不需要系统调用）

```
### spark
```
Apache Spark 是一个 分布式大数据处理框架，特点是 内存计算为主，支持批处理和流处理，是 互联网公司处理 PB 级数据、做复杂分析和实时计算的核心引擎。

内存计算为主，内存不足时才落盘？瓶颈不在磁盘IO

```
### clickhouse
```
面向联机分析处理（OLAP）的列式数据库，主打极高吞吐和低延迟的分析查询

低延时查询 ———— blk-wbt，限制后台回写保证前台响应速度？
合适调度器 ———— Nvme盘直接用none调度器或者deadline
配置多路径？

看瓶颈在哪儿，之前没发现存储相关的性能瓶颈
```
### mysql
```
开源关系型数据库管理系统（RDBMS），支持标准 SQL，常用于互联网业务的交易系统、用户/订单/资产等核心数据存储，以及日志/数据仓库的中转与汇总
美团mysql性能劣化（合入ext4优化补丁，提高读IO并发，NVME盘反压了写IO）
```
## （二）磁电盘
```
企业数据快速增长，离线介质成为降成本、绿色节能优选方案。华为推出首款72TB磁电盘，用于替代机械硬盘存储大量冷数据。SSD与磁带系统组成的MED存储设备需要定制的文件系统对数据进行管理

子功能方案设计与开发；测试用例开发与问题定位；图形环境的搭建与调试

完成挂载数量限制，mkfs/mount/lseek/unlink等基础功能点的方案设计与实现，累积开发代码3k+；
完成部分基础功能测试用例设计，累积开发代码2k+；
定位spinfs及erofs相关问题(如erofs长期存在的镜像压缩opaque属性丢失问题)，bugfix补丁累计20+；
完成崩溃重启后的清理恢复功能开发，保证系统崩溃一致性；
主导文件堆叠方案设计，实现对超大文件的支持；
主导归档文件直读方案设计，提升归档文件读性能；
完成图形环境的选定与搭建调试，保证视频文件归档及播放等基本功能正常演示。

磁电盘硬件结构：
	Client(搭载spinfs) + Server(SSD + 磁电盘)

spinfs结构：
	fuse + overlayfs + erofs

文件系统层操作流程：
	fuse挂载目录作为用户操作目录，用户对目录的操作由fuse调用spinfs对应的钩子函数进行处理
	spinfs操作的是底层的overlayfs，初始化只有一个upper目录dir_00(保存在SSD中)。
	写：
	当前只支持追加写，当追加写的数据量达到阈值，则将dir_00格式化成erofs的meta.00和blob.00两个镜像，并写入磁电盘。将meta.00挂载到arc_00，作为overlayfs的lower目录，新建dir_01作为upper目录。
	归档过程会阻塞所有文件操作，关闭所有文件后格式化镜像。写打开的文件会在overlayfs重新挂载后重新打开（写打开触发copy-up，不支持大文件，需要做文件堆叠），读打开的文件会在下一次读操作发起时重新打开。
	当arc_xx目录达到4个，会进行合并arc_xx_xx
	（写磁电盘优化：格式化成镜像后会立刻返回，不阻塞用户后续操作，镜像写入磁电盘由异步进程执行）
	读：
	未归档的文件，直接从upper目录中读取(SSD)，已归档的文件，会加载对应的镜像，挂载后共用户读取(根据文件扩展属性确认要加载哪个镜像)

设备层操作流程：
	设备存储空间分为多个container，每个container有480M空间，其中475M用于存储文件系统数据，其他空间存储硬件数据。每个container的可用空间(475M)由多个object组成，每个object大小为252K(最后一个不是)。
	erofs格式化镜像后会按4M的chunk粒度将镜像数据写入缓存(475M)，最后一个chunk不是4K；缓存数据按252K的object粒度将数据写入磁电盘，最后一个object不是252K。
	一个container写满后会继续写下一个container

问题：
	1) erofs问题 ———— 删完目录后再新建目录写其他文件，之前删掉的文件又出现
	镜像合并时从最新镜像开始，将不重复的目录或文件加入链表，重复目录如果已有的目录没有opaque属性，则会进行合并
	镜像5生成时，由于src目录有被删除的动作，因此镜像5中的src目录会带上opaque属性，预期通过opaque属性防止src目录中的文件被合并
	但在合并镜像5时，检测到已有镜像合并后的src目录没有opaque属性，会直接进行合并，同时丢失opaque属性
	
	归档阈值设置为100M
	写入文件每个为60M
	
	[root@fedora random_files]# ls src/
	random_file01 random_file03 random_file05 random_file07 random_file09
	random_file02 random_file04 random_file06 random_file08
	[root@fedora random_files]#
	
	1、将包含9个文件的目录写入
	cp -a src/ /mnt/fuse_dir/
	
	[root@fedora random_files]# ls /mnt/fuse_dir/
	[root@fedora random_files]# cp -a src/ /mnt/fuse_dir/
	cp: preserving times for '/mnt/fuse_dir/src/random_file01': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file02': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file03': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file04': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file05': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file06': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file07': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file08': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src/random_file09': Function not implemented
	cp: preserving times for '/mnt/fuse_dir/src': Function not implemented
	[root@fedora random_files]# ls /mnt/fuse_dir/src/
	random_file01 random_file03 random_file05 random_file07 random_file09
	random_file02 random_file04 random_file06 random_file08
	[root@fedora random_files]# ls /mnt/sdd/overlay_dir/
	57adc027-a6de-4587-b759-c24d6e5a2f6c/ tmp/
	[root@fedora random_files]# ls /mnt/sdd/overlay_dir/57adc027-a6de-4587-b759-c24d6e5a2f6c/ovl/
	arc_00_04 dir_05 merge work
	[root@fedora random_files]#
	
	2、整个目录删除
	rm -rf /mnt/fuse_dir/src/
	
	3、新建目录
	mkdir /mnt/fuse_dir/src
	
	4、向目录中依次拷贝文件
	[root@fedora random_files]# cp random_file01 /mnt/fuse_dir/src/
	[root@fedora random_files]# cp random_file09 /mnt/fuse_dir/src/
	[root@fedora random_files]# ls /mnt/fuse_dir/src/
	random_file01 random_file09
	[root@fedora random_files]# cp random_file02 /mnt/fuse_dir/src/
	[root@fedora random_files]# cp random_file03 /mnt/fuse_dir/src/
	[root@fedora random_files]# cp random_file04 /mnt/fuse_dir/src/
	[root@fedora random_files]# cp random_file05 /mnt/fuse_dir/src/
	[root@fedora random_files]# ls /mnt/fuse_dir/src/
	random_file01 random_file02 random_file03 random_file04 random_file05 random_file09
	[root@fedora random_files]# cp random_file06 /mnt/fuse_dir/src/
	[root@fedora random_files]# ls /mnt/fuse_dir/src/
	random_file01 random_file03 random_file05 random_file07 random_file09
	random_file02 random_file04 random_file06 random_file08
	[root@fedora random_files]#
	拷贝完 random_file06 后多出了 random_file07 和 random_file08
	
	整个复现过程包含两次镜像合并：
	1、meta.00~meta.04合并成meta.00_04
	2、meta.00_04和meta.05~meta.08合并成meta.00_08
	问题发生在第二次镜像合并时
	
	erofs合并镜像时先从高层的待合并镜像开始处理，依次将没有加入mergedir的结点加入mergedir的i_subdirs链表中，最后处理base镜像。
	如图中依次处理meta.08~meta.05
	
	在遍历镜像文件(目录)的过程中，如果i_subdirs中没有该文件(目录)，则新生成结点插入(src目录在遍历meta.08时插入，其他文件在遍历后续镜像时插入)
	如果发现有重复的目录，且已有的目录结点没有opaque，则合并目录(erofs_rebuild_dirent_iter中的逻辑实现)
	
	在处理meta.08时生成src结点插入链表，由于meta.08中src目录没有opaque属性，则插入结点的链表也没有该属性，后续所有同名目录都会被合并
	
	在处理meta.05时，发现已有src结点，会直接进行合并操作，虽然meta.05中src目录有opaque属性，用于屏蔽下层镜像中的同名目录，但这个属性并不会被读取，这就导致最后在合并base镜像时会将镜像中的src目录进行合并
	
	如图在处理最后base镜像时，由于检查到src目录并没有opaque属性，因此会遍历base镜像中src目录的所有文件，其中randomfile_06和random_file07在上层镜像中都没有，这里就会生成新的结点插入。
	但我们的预期是meta.05中的src结点所携带的opaque属性会在遍历meta.05后续镜像时被设置在mergedir中的src结点上，从而在处理base镜像时直接跳过base镜像中的src目录

	2) 基于22.03 SP4+XFCE+mpv，在mp4文件归档后拷贝到其他目录，可正常播放；直接从fuse目录打开播放因getattr失败无法播放
	通过 nullpath_ok 可不指定文件 path，通过用户态文件系统在open时设置的 fh 来进行 fgetattr 等操作，mpv 在 getattr 前打开了文件，fuse在getattr时没有传path，只传递了fh给spinfs，而spinfs还没有支持fh

图形环境的搭建与调试：
	对比 gnome/ukui/kiran
	选择XFCE原因：1) IO大小不变； 2) 文件拷贝过程中有进度条和速率显示
	问题：
		1) 环境搭建
		物理环境链接不稳定；qemu搭建的环境图形界面鼠标光标存在随机偏移；
		2) 打开目录会打开所有文件，严重影响速度
		XFCE对于未知格式的文件，会打开并读取头部数据，这导致打开目录时，所有归档文件都被打开并读取

```

## NFSD
```
向华为GTS产品交付NFSD，对相关内核版本进行质量加固，提升服务稳定性

分析回合主线补丁600+，加固版本质量；
处理问题100+，其中有效问题50+，解决了linux存在多年的acl资源释放不当导致UAF，文件打开冲突处理导致内存泄漏等问题，进一步提升版本质量，并贡献社区补丁10+；
输出DT用例10+，提升NFSD覆盖率80%+，为后续版本可靠性提供保障。

```
