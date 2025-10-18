# summarize
## （一）互联网场景性能优化
### （1）redis
```
内存数据库,用于实时性很强的场景
高性能缓存和中间态存储组件，承接大量短小、频繁的请求

为什么可以用io_uring提升性能？
1、单线程模型（io_uring实例初始化后作为一个fd由进程管理，也支持多进程共享？没研究过）
2、系统调用次数多是一个性能瓶颈（io_uring通过共享的ring buffer传递请求和执行结果，减少系统调用次数）

io_uring 通过减少系统调用、批量化提交与完成、内核态流水化及异步文件/网络 I/O，能显著降低开销与尾延迟，从而提升 Redis 在高并发小请求与持久化压力下的整体性能。
（关键还是减少系统调用，减少普通的read/write系统调用，也减少原本epoll查询结果的系统调用，使用io_uring的话可以直接检查CQ，不需要系统调用）

```
### （2）spark
```
Apache Spark 是一个 分布式大数据处理框架，特点是 内存计算为主，支持批处理和流处理，是 互联网公司处理 PB 级数据、做复杂分析和实时计算的核心引擎。

内存计算为主，内存不足时才落盘？瓶颈不在磁盘IO

```
### （3）clickhouse
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

## （三）文件系统
### （1）vfs
#### 1) mount
```
mount("/dev/sde", "/mnt/sde", "ext4", 0, NULL) = 0
生成 root dentry, mount, root inode, superblock
do_mount
 path_mount
  do_new_mount
   get_fs_type // 根据文件系统类型获取 file_system_type
   fs_context_for_mount
    alloc_fs_context // 分配初始化 fs_context
   vfs_parse_fs_string // 通过kv将设备名保存在fc中
   do_new_mount_fc
    fc_mount
	 vfs_get_tree
	  ext4_get_tree // fc->ops->get_tree
	   get_tree_bdev
	    get_tree_bdev_flags
		 lookup_bdev // 查找源设备
		 sget_dev
		  sget_fc // 分配初始化 superblock
		   alloc_super
		 ext4_fill_super
		  __ext4_fill_super
		   ext4_iget // 获取 root inode
		    __ext4_iget
			 iget_locked // 查找到则使用，否则新分配并初始化，加入全局hash表
		   d_make_root // sb->s_root
		    d_alloc_anon // 分配初始化 root dentry
			 __d_alloc
			d_instantiate // 在 dentry 中填充 inode 信息
		 fc->root = dget(s->s_root) // 设置 root dentry
	 vfs_create_mount
	  alloc_vfsmnt // 分配 mount，返回内嵌的 vfsmount
    do_add_mount
	 graft_tree
	  attach_recursive_mnt // 将 mount 添加到 mount 树中
	   get_mountpoint // 标记当前挂载目录为挂载点，DCACHE_MOUNTED，在后续查找文件时可以从dentry上或许最新的一个mount(最后一次在这个目录上挂载的文件系统的mount)
```
#### 2) umount
```
umount2("/mnt/sde", 0)
ksys_umount
 path_umount
  can_umount // 判断是否可以卸载
  do_umount
   nfs_umount_begin // sb->s_op->umount_begin ，带有 MNT_FORCE 标记时执行，如nfs会终止所有rpc_task
   umount_tree
  mntput_no_expire
   schedule_delayed_work // delayed_mntput_work

delayed_mntput_work
 delayed_mntput
  cleanup_mnt
   deactivate_super
    deactivate_locked_super
	 put_super // 释放 superblock
   call_rcu // delayed_free_vfsmnt

delayed_free_vfsmnt // 异步释放 mount
 free_vfsmnt

dentry 怎么释放？
    dentry_free+1
    __dentry_kill+328
    dput.part.0+548
    shrink_dcache_for_umount+135
    generic_shutdown_super+32
    kill_block_super+26
    ext4_kill_sb+34
    deactivate_locked_super+51
    cleanup_mnt+186
    task_work_run+92
    exit_to_user_mode_loop+292
    do_syscall_64+453
    entry_SYSCALL_64_after_hwframe+118
```
#### 3) open
```
https://zhuanlan.zhihu.com/p/471175983
根据路径字符和指定fd，先找到起始path/dentry，再一级级查找生成path/dentry，最后返回与新 file 关联的 fd
do_sys_open
 do_sys_openat2
  get_unused_fd_flags // 获取可用的fd
  do_filp_open // 打开文件
   set_nameidata // 初始化 nameidata ，包含待打开文件的路径信息
   path_openat // 使用 nameidata 和 open_flags 打开文件
    alloc_empty_file // 分配新的 file
	path_init // 返回查询路径
			  // 1) 全路径，直接返回
			  // 2) 相对路径，dfd是当前目录，则获取当前目录的 path 和 inode 保存到 nd 中
			  // 3) 相对路径，dfd是指定路径，则获取当前目录的 path 和 inode 保存到 nd 中
	link_path_walk
	 walk_component
	  lookup_fast // 快速路径，当前查找位置对应的hash表上是否有与 nd->last 匹配的 dentry
	  lookup_slow // 慢速路径
	   __lookup_slow
	    d_alloc_parallel // 分配新的 dentry ，加入对应的hash表
	  step_into
	do_open
  fd_install // 关联 file 和 fd
```

#### 4) close
```
close
 file_close_fd // 根据 fd 获取 file
 filp_flush
 fput_close_sync
  __fput
   dput // 释放 dentry
   file_free // 释放 file
```

#### 5) read
```
ksys_read
 file_ppos // 获取文件偏移
 vfs_read
  new_sync_read
   init_sync_kiocb // 初始化 kiocb ，包含源信息(文件，偏移)
   iov_iter_ubuf // 初始化 iov_iter ，包含目的信息(buf, len)
  ext4_file_read_iter // file->f_op->read_iter

DAX
绕过页缓存 + 绕过块层，直接映射文件页到用户地址空间
直接通过映射的设备地址将数据读到用户态buf
ext4_dax_read_iter
 dax_iomap_rw
  iomap_iter
   ext4_iomap_begin_report // ops->iomap_begin
  dax_iomap_iter
   dax_direct_access
    pmem_dax_direct_access // dax_dev->ops->direct_access
	 __pmem_direct_access // 读出数据，返回地址 kaddr
   dax_copy_to_iter // 将地址 kaddr 中的数据数据拷贝到用户态buf
    _copy_to_iter

DIO
绕过页缓存，直接 DMA 到用户缓冲区
不涉及文件 mapping ，分配page结构体描述用户态buf，关联page和bio后下发bio，将数据直接传递给用户态buf
ext4_dio_read_iter
 iomap_dio_rw // ext4_iomap_ops
  __iomap_dio_rw
   inode_dio_begin
   iomap_dio_iter
    iomap_dio_bio_iter
	 iomap_dio_alloc_bio // 分配初始化 bio
	 iomap_sector // 获取磁盘位置，设置 bio->bi_iter.bi_sector ， IO 起始位置
	 bio_iov_iter_get_bdev_pages
	  bio_iov_iter_get_pages_aligned
	   __bio_iov_iter_get_pages
	    iov_iter_extract_pages
		 iov_iter_extract_user_pages
		  want_pages_array // 当前要读取的数据量需要多少个page，分配对应数量的page
		  pin_user_pages_fast // 使用用户态buf地址设置page，这样DIO读的数据就直接读到用户态buf中了
	    bio_add_folio
		 bio_add_page
		  __bio_add_page
		   bvec_set_page // 关联 bio 与 page。 这个 page 是什么，和 mapping 中的 page 什么差别？
		   bio->bi_iter.bi_size += len // IO 大小
    iomap_dio_submit_bio 
	 submit_bio // 提交bio
 iomap_dio_complete

// DIO 完成后
iomap_dio_bio_end_io
 iomap_dio_done
  blk_wake_io_task // 唤醒等待的进程 dio->submit.waiter


Buffer io
通过页缓存读写文件
将数据读到 folio 中，再重 folio 拷贝到用户态buf
generic_file_read_iter
 filemap_read
  folio_batch_init // 初始化 folio_batch 用于保存文件对应的 folio
  filemap_get_pages
   filemap_get_read_batch // 从文件的 mapping 中查找待读取范围对应的 folio
   page_cache_sync_ra // 没找到则做预读
    do_page_cache_ra
	 page_cache_ra_unbounded
	  ractl_alloc_folio // 分配 folio
	  filemap_add_folio // folio 加入 mapping
	  read_pages
	   ext4_read_folio // aops->read_folio
	    ext4_mpage_readpages
		 bio_alloc // 分配 bio
		 bio_add_folio // 关联 bio 和 folio
		 submit_bio // 提交bio
  copy_folio_to_iter // 将 folio 中的数据拷贝到用户态buf

```

#### 6) write
```
ksys_write
 vfs_write
  new_sync_write
   init_sync_kiocb // 初始化 kiocb ，包含目的信息(文件，偏移)
   iov_iter_ubuf // 初始化 iov_iter ，包含源信息(buf, len)
   ext4_file_write_iter // filp->f_op->write_iter

DAX
ext4_dax_write_iter
 dax_iomap_rw
  iomap_iter
  dax_iomap_iter
   dax_direct_access // 获取对应的设备地址
   dax_copy_from_iter // 将用户态数据拷贝到设备地址

DIO
用新分配的page描述用户buf，再下发IO直接将数据写入设备
ext4_dio_write_iter

Buffer io
ext4_buffered_write_iter
 generic_perform_write
  ext4_da_write_begin // a_ops->write_begin 准备 folio
  copy_folio_from_iter_atomic // 拷贝用户态数据到 folio
  ext4_da_write_end // a_ops->write_end
   ext4_da_do_write_end
    block_write_end
	 block_commit_write
	  mark_buffer_dirty
	   __mark_inode_dirty

ksys_write
 vfs_write
  new_sync_write
   init_sync_kiocb // 初始化 kiocb ，包含目的信息(文件，偏移)
   iov_iter_ubuf // 初始化 iov_iter ，包含源信息(buf, len)
   ext4_file_write_iter // filp->f_op->write_iter

DAX
ext4_dax_write_iter
 dax_iomap_rw
  iomap_iter
  dax_iomap_iter
   dax_direct_access // 获取对应的设备地址
   dax_copy_from_iter // 将用户态数据拷贝到设备地址

DIO
用新分配的page描述用户buf，再下发IO直接将数据写入设备
ext4_dio_write_iter

Buffer io
ext4_buffered_write_iter
 generic_perform_write
  ext4_da_write_begin // a_ops->write_begin 准备 folio
  copy_folio_from_iter_atomic // 拷贝用户态数据到 folio
  ext4_da_write_end // a_ops->write_end
   ext4_da_do_write_end
    block_write_end
	 block_commit_write
	  mark_buffer_dirty
	   __mark_inode_dirty
	    inode_attach_wb
		 __inode_attach_wb
		  cmpxchg // 设置 &inode->i_wb
	    wb_wakeup_delayed


后台回写进程的初始化流程：
创建设备的时候和 gendisk 一起初始化
__alloc_disk_node
 bdi_alloc
  bdi_init
   cgwb_bdi_init
    wb_init
	 INIT_DELAYED_WORK // &wb->dwork wb_workfn

后台回写进程怎么跑起来的：
1) buffer write 写完数据后 queue work
generic_perform_write
 ...
 a_ops->write_end
  ...
  __mark_inode_dirty
   wb_wakeup_delayed
    queue_delayed_work // wb->dwork
2) 脏页超水线后 queue work
generic_perform_write
 balance_dirty_pages_ratelimited
  balance_dirty_pages_ratelimited_flags
   balance_dirty_pages
    wb_start_background_writeback
	 wb_wakeup
	  mod_delayed_work // wb->dwork
3) sync 后 queue work
do_fsync
 vfs_fsync
  vfs_fsync_range
   mark_inode_dirty_sync
    __mark_inode_dirty
	 wb_wakeup_delayed
	  queue_delayed_work // wb->dwork


后台回写流程怎么确认哪些数据需要回写：



回写的数据是怎么来的：
```

### （2）ext4
```
https://blog.csdn.net/xuhaitao23/article/details/112404331
```
### （3）xfs
```
https://zhuanlan.zhihu.com/p/352464797
```
### （4）NFSD
```
向华为GTS产品交付NFSD，对相关内核版本进行质量加固，提升服务稳定性

分析回合主线补丁600+，加固版本质量；
处理问题100+，其中有效问题50+，解决了linux存在多年的acl资源释放不当导致UAF，文件打开冲突处理导致内存泄漏等问题，进一步提升版本质量，并贡献社区补丁10+；
输出DT用例10+，提升NFSD覆盖率80%+，为后续版本可靠性提供保障。

```
## （四）其他
### （1）io_uring
#### 1) io_uring基本结构和流程
<img width="724" height="841" alt="image" src="https://github.com/user-attachments/assets/bb739a91-4c18-496d-a465-594ad5a5311b" />
<img width="724" height="630" alt="image" src="https://github.com/user-attachments/assets/087cd837-cd88-4f2c-9fdd-04e3d11a5b1f" />
<img width="242" height="763" alt="image" src="https://github.com/user-attachments/assets/e6e9dc8d-5881-42cd-9f7c-bb92e5963dfd" />
<img width="430" height="833" alt="image" src="https://github.com/user-attachments/assets/c553d908-0eec-43a3-a34a-497cf9eb7155" />

```
/*
 * entries -- 指定SQ的规模，CQ规模为SQ两倍
 *				io_uring_queue_init_params 可通过 IORING_SETUP_CQSIZE 标记指定CQ规模
 * ring -- io_uring 指针，创建出的io_uring实例
 * flags -- IORING_SETUP_IOPOLL/IORING_SETUP_SQPOLL 等
 */
io_uring_queue_init
 io_uring_queue_init_params // io_uring_params
  __sys_io_uring_setup // io_uring_setup 系统调用
  io_uring_queue_mmap
   io_uring_mmap
    __sys_mmap // IORING_OFF_SQ_RING 映射 io_rings(ctx->rings)，主要是 sq->array 对应 sq_entries个u32 即SQ
	__sys_mmap // IORING_OFF_CQ_RING 映射 io_rings(ctx->rings)，主要是 cq->cqes 对应 cq_entries个io_uring_cqe 即CQ
    __sys_mmap // IORING_OFF_SQES 映射 io_uring_sqe(ctx->sq_sqes)，即SQE数组
  sq_array[index] = index; // SQ 与 SQE 一对一映射


io_uring_setup
 io_uring_create
  io_ring_ctx_alloc // 分配初始化 io_ring_ctx
  io_allocate_scq_urings // 根据 io_uring_params 初始化 io_ring_ctx
   rings_size // 计算 io_rings 大小，包括 （io_rings内嵌成员 + cq_entries个io_uring_cqe + sq_entries个u32）
			  // cq_entries个io_uring_cqe 即 cqes 数组
			  // sq_entries个u32 即 sq_array，sq偏移数组
   io_mem_alloc // 分配初始化 io_urings(内嵌CQ) --> ctx->rings
   ctx->sq_array // sq偏移数组，分配 io_rings 时一起分配，对应用户态 sq->array
   io_mem_alloc // 分配SQE数组 --> ctx->sq_sqes
  io_sq_offload_create // 根据不同的flags做特殊处理
   // IORING_SETUP_SQPOLL
   io_get_sq_data // 通过 IORING_SETUP_ATTACH_WQ 标记可以使用指定的 io_sq_data，多io_uring共享io_sq_data
    kzalloc // 分配初始化 io_sq_data --> ctx->sq_data
   io_sq_thread_park // 暂停 io_sq_thread 运行
   list_add // ctx->sqd_list --> sqd->ctx_list io_ring_ctx 添加到 io_sq_data 链表中
   io_sqd_update_thread_idle // 更新 sqd->sq_thread_idle
   io_sq_thread_unpark // 恢复 io_sq_thread 运行
   create_io_thread // 创建 io_sq_thread 进程 --> sqd->thread
   io_uring_alloc_task_context // 分配初始化 io_uring_task ，由 io_sq_thread 使用 --> task->io_uring
    io_init_wq_offload
	 io_wq_create // 分配初始化 io_wq --> tctx->io_wq
	  kzalloc_node // 为每个numa分配一个 io_wqe --> wq->wqes[node]
  io_rsrc_node_switch_start
   io_rsrc_node_alloc // 分配初始化 io_rsrc_node --> ctx->rsrc_backup_node
  io_uring_get_file
   anon_inode_getfile // 基于匿名inode创建一个file， f_op 是 io_uring_fops，private_data 是 io_ring_ctx，主要用于用户态mmap
  io_uring_install_fd // 获取fd返回给用户态


// io_sq_thread 在使能 IORING_SETUP_SQPOLL 后会同步处理sqe，处理的方式可能是同步处理，也可能是根据io_wq创建worker后异步处理
io_sq_thread
 __io_sq_thread // io_sq_data 可以对应多个 io_ring_ctx，遍历一个 io_ring_ctx 进行处理
  io_submit_sqes
   io_alloc_req // 分配 req (io_kiocb)
   io_get_sqe // 获取 sqe (io_uring_sqe)
    io_submit_sqe
	 io_init_req // 初始化 req
	 io_req_prep // 调用当前操作对应的 prep 函数
	 io_queue_sqe
	  __io_queue_sqe // 同步执行
	   io_issue_sqe
	    io_prep_linked_timeout
		 __io_prep_linked_timeout // link 请求？？？
	  io_queue_async_work // 异步执行

io_submit_sqe
 io_queue_sqe
  io_queue_async_work // 异步执行
   io_wq_enqueue // tctx->io_wq req->work
    io_wqe_enqueue
	 io_wqe_create_worker
	  create_io_worker
	   kzalloc_node // 分配初始化 io_worker
	   create_io_thread // 创建 io_wqe_worker 进程 --> worker->task
	   io_init_new_worker
	    list_add_tail_rcu // worker->all_list --> wqe->all_list io_worker 由wqe管理
```
#### 2) io_uring高级特性


#### 3) io_uring问题

老版本 io_uring 经常遇到 uaf 问题，合入重构补丁后解决
io_uring: import 5.15-stable io_uring
https://git.kernel.org/pub/scm/linux/kernel/git/stable/linux.git/commit/?h=v5.10.245&id=788d0824269bef539fe31a785b1517882eafed93
回合5.15上io_uring的实现到5.10(其中删除io_identity可修复CVE-2023-0240)
修复补丁带有如下补丁的修改：
https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?h=v6.2-rc7&id=3bfe6106693b6b4ba175ad1f929c4660b8f59ca8
该补丁修改了进程信息的传递方式，原本通过io_identity传递，现在修改了worker进程的创建方式，由后台线程创建修改为manager创建，可直接继承父进程信息
worker线程原本由后台线程创建，处理req时所需的fs/mm/nsproxy等信息由io_identity携带，当前修改为manager进程创建，直接继承父进程的信息

1、printk bug导致CPU不调度，阻塞io_uring实例释放
io_ring_exit_work 退出流程中，io_ring_ctx 内嵌的 percpu_ref 已经被 kill(percpu_ref_kill)，最后一个计数一直没释放
```
percpu_ref_kill
 percpu_ref_kill_and_confirm
  __percpu_ref_switch_mode
   __percpu_ref_switch_to_atomic
    percpu_ref_get // 获取计数
     call_rcu // percpu_ref_switch_to_atomic_rcu

percpu_ref_switch_to_atomic_rcu
 atomic_long_add // 将data->count减去 PERCPU_COUNT_BIAS
 percpu_ref_call_confirm_rcu
  percpu_ref_put // 预期这里释放计数
从count计数为 0x8000000000000001 可确定 percpu_ref_switch_to_atomic_rcu 未执行
未执行原因为宽限期未结束
```
从dmesg和core中发现进程 3386 一直占用着CPU0，导致CPU0无法调度，宽限期无法结束

2、文件系统只读，无法刷脏页，worker退出流程hungtask
```
INFO: task io_uring_stress:290554 blocked for more than 606 seconds.
      Not tainted 5.10.0-00002-g642474be0f3a-dirty #2
"echo 0 > /proc/sys/kernel/hung_task_timeout_secs" disables this message.
task:io_uring_stress state:D stack:    0 pid:290554 ppid:     1 flags:0x00000209
Call trace:
 __switch_to+0x98/0xf0 arch/arm64/kernel/process.c:600
 context_switch kernel/sched/core.c:4233 [inline]
 __schedule+0x620/0xed8 kernel/sched/core.c:5448
 schedule+0xd8/0x1cc kernel/sched/core.c:5526
 schedule_timeout+0x390/0x43c kernel/time/timer.c:2113
 do_wait_for_common kernel/sched/completion.c:85 [inline]
 __wait_for_common kernel/sched/completion.c:106 [inline]
 wait_for_common+0x14c/0x260 kernel/sched/completion.c:117
 wait_for_completion+0x20/0x40 kernel/sched/completion.c:138
 io_wq_exit_workers+0x23c/0x4e0 io_uring/io-wq.c:1258
 io_wq_put_and_exit+0x30/0x80 io_uring/io-wq.c:1293
 io_uring_clean_tctx io_uring/io_uring.c:9706 [inline]
 io_uring_cancel_generic+0x464/0x53c io_uring/io_uring.c:9775
 __io_uring_cancel+0x1c/0x40 io_uring/io_uring.c:9789
 io_uring_files_cancel include/linux/io_uring.h:47 [inline]
 do_exit+0xe0/0x770 kernel/exit.c:767
 do_group_exit+0x64/0x124 kernel/exit.c:916
 do_notify_qmp_cmd_name: human-monitor-command, arguments: {"command-line": "info registers", "cpu-index": 1}
 work_pending+0xc/0xa0
```

故障注入导致文件系统变只读，无法刷脏页，请求无法完成
```
// 一直尝试刷脏页，但脏页无法正常下刷，无法满足门限，导致无法退出
balance_dirty_pages
 wb_start_background_writeback
  wb_wakeup
   mod_delayed_work // wb->dwork

// ext4返回了 -EROFS，导致脏页无法下刷
wb_workfn
 wb_do_writeback
  wb_writeback
   writeback_sb_inodes
    __writeback_single_inode
     do_writepages
      ext4_writepages
       ext4_test_mount_flag(inode->i_sb, EXT4_MF_FS_ABORTED)
       // ret = -EROFS

// 故障注入导致IO错误，设置了 EXT4_MF_FS_ABORTED
__ext4_error
 // "EXT4-fs error (device %s): %s:%d: comm %s: %pV\n"
 ext4_handle_error
  ext4_set_mount_flag(sb, EXT4_MF_FS_ABORTED)
```

kill -9 无法杀死worker进程，可使用tkill

```
#include <signal.h>
#include <sys/syscall.h>
#include <unistd.h>

void main()
{
        syscall(SYS_tkill, 263105, 9);
}

[2025-06-10 20:14:07]  [root@localhost tracing]# cat /proc/t263105263105/stack 
[2025-06-10 20:14:17]  
[2025-06-10 20:14:17]  [<0>] balance_dirty_pages+0x466/0x1420
[2025-06-10 20:14:17]  [<0>] balance_dirty_pages_ratelimited+0x996/0xf30
[2025-06-10 20:14:17]  [<0>] generic_perform_write+0x22e/0x2f0
[2025-06-10 20:14:17]  [<0>] ext4_buffered_write_iter+0x11e/0x200
[2025-06-10 20:14:17]  [<0>] io_write+0x250/0x5b0
[2025-06-10 20:14:17]  [<0>] io_issue_sqe+0x2a4/0x13d0
[2025-06-10 20:14:17]  [<0>] io_wq_submit_work+0xe5/0x1b0
[2025-06-10 20:14:17]  [<0>] io_worker_handle_work+0x219/0x610
[2025-06-10 20:14:17]  [<0>] io_wqe_worker+0x461/0x4b0
[2025-06-10 20:14:17]  [<0>] ret_from_fork+0x1f/0x30
[2025-06-10 20:14:20]  [root@localhost tracing]# cat /proc/263105/stack 
[2025-06-10 20:16:07]  
[2025-06-10 20:16:07]  cat: /proc/263105/stack: No such file or directory
[2025-06-10 20:16:10]  
[2025-06-10 20:16:11]  [root@localhost tracing]# 
```

```
balance_dirty_pages
 fatal_signal_pending
  __fatal_signal_pending // 检测 p->pending.signal

kill系统调用
kill_something_info
 kill_proc_info
  kill_pid_info
   group_send_sig_info // type 为 PIDTYPE_TGID
    do_send_sig_info
	 send_signal
	  __send_signal
	   pending = (type != PIDTYPE_PID) ? &t->signal->shared_pending : &t->pending
	   // 信号设置在 shared_pending 上

tkill系统调用
do_tkill
 do_send_specific
  do_send_sig_info // PIDTYPE_PID
  // 信号设置在 pending 上
```

3、用户态unregister buffer请求卡住
```
[root@nfs_test3 ~]# ps aux | grep debug
polkitd    442  0.0  0.0 534192 17024 ?        Ssl  15:33   0:00 /usr/lib/polkit-1/polkitd --no-debug
root      2938  0.5  0.0   6492   688 ttyS0    S+   16:38   0:00 ./debug
root      2940  0.0  0.0 119468   964 pts/0    S+   16:38   0:00 grep --color=auto debug
[root@nfs_test3 ~]# cat /proc/2938/stack
[<0>] io_rsrc_ref_quiesce.part.0.constprop.0.cold+0x71/0x14e
[<0>] __io_uring_register+0x62f/0x8c0
[<0>] __se_sys_io_uring_register+0x143/0x230
[<0>] do_syscall_64+0x2c/0x40
[<0>] entry_SYSCALL_64_after_hwframe+0x6c/0xd6
[root@nfs_test3 ~]# 
```

问题流程
```
io_uring_register
 __io_uring_register
  io_sqe_buffers_unregister
   io_rsrc_ref_quiesce
    io_rsrc_node_switch
	 rsrc_node->rsrc_data = data_to_kill // data 关联 node
	 list_add_tail // node 加入 ctx->rsrc_ref_list
	 atomic_inc // 增加 data 计数，表示链表对 data 的引用，当前计数为 2
	            // 在等待过程中如果被强制kill，会再次循环加入链表，计数会再增加
	 percpu_ref_kill // rsrc_node->refs 减 node 计数，若减为0则调用 io_rsrc_node_ref_zero
	atomic_dec_and_test // 减去 data 初始化计数，当前计数为 1
	wait_for_completion_interruptible // 等待 done 被设置

// 每个node在计数减为0的时候都会调用一次这个release回调，将node->done置位true
io_rsrc_node_ref_zero
 list_first_entry // 遍历 ctx->rsrc_ref_list 处理已插入的 node
 1) 当前遍历到的node没有设置 node->done，直接退出循环
 2) 当前遍历到的node设置了 node->done，node 加入 ctx->rsrc_put_llist
 // 如果有 node 设置了 node->done ，会唤醒 put_work
 mod_delayed_work // 触发 rsrc_put_work io_rsrc_put_work

// 预期情况
io_rsrc_put_work
 llist_del_all // 从 ctx->rsrc_put_llist 中获取 node
 __io_rsrc_put_work
  atomic_dec_and_test // 减 rsrc_data->refs 计数
  complete // 计数减为0，设置 &rsrc_data->done，唤醒 unregister 进程

// 实际情况
有一个node的计数没有减为0，不会 queue io_rsrc_put_work

io_req_set_rsrc_node
 percpu_ref_get // req 获取 node 计数

io_dismantle_req
 percpu_ref_put // req 释放 node 计数

10:52:17 localhost.localdomain kernel: io_rsrc_node_alloc 7925 node ffff888107e62688 done false
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c2340 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c2340 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_dismantle_req req ffff88812c6c2340 put node refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88817a9961c0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88817a9961c0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88811d9a8cc0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88811d9a8cc0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:33 localhost.localdomain kernel: io_dismantle_req req ffff88811d9a8cc0 put node refs ffff888107e62688 done
10:53:35 localhost.localdomain kernel: io_req_set_rsrc_node req ffff888150b6afc0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:35 localhost.localdomain kernel: io_req_set_rsrc_node req ffff888150b6afc0 get node ffff888107e62688 refs ffff888107e62688 done
10:53:35 localhost.localdomain kernel: io_dismantle_req req ffff888150b6afc0 put node refs ffff888107e62688 done
10:53:51 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c0f40 get node ffff888107e62688 refs ffff888107e62688 done
10:53:51 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c0f40 get node ffff888107e62688 refs ffff888107e62688 done
10:53:51 localhost.localdomain kernel: io_dismantle_req req ffff88812c6c0f40 put node refs ffff888107e62688 done
10:54:00 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88811d9af5c0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:00 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88811d9af5c0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:00 localhost.localdomain kernel: io_dismantle_req req ffff88811d9af5c0 put node refs ffff888107e62688 done
10:54:02 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c0cc0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:02 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c0cc0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:02 localhost.localdomain kernel: io_dismantle_req req ffff88812c6c0cc0 put node refs ffff888107e62688 done
10:54:09 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c34c0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:09 localhost.localdomain kernel: io_req_set_rsrc_node req ffff88812c6c34c0 get node ffff888107e62688 refs ffff888107e62688 done
10:54:09 localhost.localdomain kernel: io_dismantle_req req ffff88812c6c34c0 put node refs ffff888107e62688 done
10:54:09 localhost.localdomain kernel: io_rsrc_node_switch data ffff8881328e6648 refs 2 node ffff888107e62688
10:54:09 localhost.localdomain kernel: io_rsrc_node_switch kill node ffff888107e62688 done
10:54:11 localhost.localdomain kernel: io_rsrc_node_ref_zero 7892 node ffff888107e62688 done false
10:54:11 localhost.localdomain kernel: io_rsrc_node_ref_zero 7901 node ffff888107e62688

复现后通过打印确认 req ffff88817a9961c0 没有释放 node 计数
```

让io_uring实例文件的poll回调返回0导致的该问题
```
static __poll_t io_uring_poll(struct file *file, poll_table *wait)
{
	struct io_ring_ctx *ctx = file->private_data;
	__poll_t mask = 0;

	poll_wait(file, &ctx->poll_wait, wait);
	/*
	 * synchronizes with barrier from wq_has_sleeper call in
	 * io_commit_cqring
	 */
	smp_rmb();
//	if (!io_sqring_full(ctx))
//		mask |= EPOLLOUT | EPOLLWRNORM;

	/*
	 * Don't flush cqring overflow list here, just do a simple check.
	 * Otherwise there could possible be ABBA deadlock:
	 *      CPU0                    CPU1
	 *      ----                    ----
	 * lock(&ctx->uring_lock);
	 *                              lock(&ep->mtx);
	 *                              lock(&ctx->uring_lock);
	 * lock(&ep->mtx);
	 *
	 * Users may get EPOLLIN meanwhile seeing nothing in cqring, this
	 * pushs them to do the flush.
	 */
//	if (io_cqring_events(ctx) || test_bit(0, &ctx->check_cq_overflow))
//		mask |= EPOLLIN | EPOLLRDNORM;

	return mask;
}
```

```
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <fcntl.h>
#include <unistd.h>
#include <liburing.h>

#define BUF_SIZE 4096
#define QUEUE_DEPTH 8
#define FILE_NAME "fixed_write_test.txt"

int main() {
    struct io_uring ring;
    int file_fd, ret;

    // ===== 1. 初始化 io_uring 实例 =====
    if (io_uring_queue_init(QUEUE_DEPTH, &ring, 0) != 0) {
        perror("io_uring_queue_init");
        return -1;
    }

    // ===== 2. 准备固定缓冲区 =====
    char *fixed_buf;
    if (posix_memalign((void**)&fixed_buf, BUF_SIZE, BUF_SIZE)) {
        perror("posix_memalign");
        io_uring_queue_exit(&ring);
        return -1;
    }
    strcpy(fixed_buf, "Hello, io_uring fixed write!");  // 写入测试数据

    // ===== 3. 注册固定缓冲区到内核 =====
    struct iovec iov = { .iov_base = fixed_buf, .iov_len = BUF_SIZE };
    if (io_uring_register_buffers(&ring, &iov, 1) < 0) {
        perror("io_uring_register_buffers");
        free(fixed_buf);
        io_uring_queue_exit(&ring);
        return -1;
    }

    // ===== 4. 打开目标文件 =====
    file_fd = open(FILE_NAME, O_WRONLY | O_CREAT | O_TRUNC, 0644);
    if (file_fd < 0) {
        perror("open");
        free(fixed_buf);
        io_uring_queue_exit(&ring);
        return -1;
    }

    // ===== 5. 准备并提交 fixed_write 请求 =====
    struct io_uring_sqe *sqe = io_uring_get_sqe(&ring);
    if (!sqe) {
        perror("io_uring_get_sqe");
        close(file_fd);
        free(fixed_buf);
        io_uring_queue_exit(&ring);
        return -1;
    }

    // 设置 fixed_write 参数 [3,8](@ref)
    io_uring_prep_write_fixed(
        sqe,                    // SQE 指针
        ring.ring_fd,                // 文件描述符
        fixed_buf,              // 缓冲区地址
        strlen(fixed_buf),      // 数据长度
        0,                      // 文件偏移（0=从文件头开始）
        0                       // 缓冲区索引（固定缓冲区的索引）
    );

    // 提交请求到内核 [2,10](@ref)
    io_uring_submit(&ring);

    // ===== 6. 等待并处理完成事件 =====
/*
    struct io_uring_cqe *cqe;
    if (io_uring_wait_cqe(&ring, &cqe) < 0) {
        perror("io_uring_wait_cqe");
        close(file_fd);
        free(fixed_buf);
        io_uring_queue_exit(&ring);
        return -1;
    }
*/
    // 检查写入结果 [11](@ref)
 /*
    if (cqe->res < 0) {
        fprintf(stderr, "Write error: %s\n", strerror(-cqe->res));
    } else {
        printf("Success! Wrote %d bytes to %s\n", cqe->res, FILE_NAME);
    }
*/
    // ===== 7. 清理资源 =====
  /*
    io_uring_cqe_seen(&ring, cqe);      // 标记 CQE 已处理
 */
    if (io_uring_unregister_buffers(&ring) < 0) {
            perror("io_uring_unregister_buffers");
    }

    close(file_fd);                     // 关闭文件
    free(fixed_buf);                     // 释放缓冲区
    io_uring_queue_exit(&ring);          // 销毁 io_uring 实例

    return 0;
}

```



4、写socket文件发送消息后对端不接收，导致请求无法完成
写socket文件卡住
```
[root@localhost ~]# ps -eT | grep io_uring
   2184    2184 ttyS0    00:00:00 io_uring_stress
   2186    2186 ?        00:00:00 io_uring_stress
   2186    2195 ?        00:00:00 io_uring_stress
[root@localhost ~]# cat /proc/2186/stack
[<0>] hrtimer_nanosleep+0x120/0x230
[<0>] common_nsleep+0x5f/0x70
[<0>] __se_sys_clock_nanosleep+0x18e/0x230
[<0>] do_syscall_64+0x2b/0x40
[<0>] entry_SYSCALL_64_after_hwframe+0x6c/0xd6
[root@localhost ~]# cat /proc/2195/stack
[<0>] io_rsrc_ref_quiesce.part.0+0x168/0x2b0
[<0>] __io_uring_register+0x583/0x1100
[<0>] __se_sys_io_uring_register+0x176/0x370
[<0>] do_syscall_64+0x2b/0x40
[<0>] entry_SYSCALL_64_after_hwframe+0x6c/0xd6
[root@localhost ~]# netstat -tuanp
Active Internet connections (servers and established)
Proto Recv-Q Send-Q Local Address           Foreign Address         State       PID/Program name
tcp        0      0 0.0.0.0:8080            0.0.0.0:*               LISTEN      2184/./tools/io_uri
tcp        0      0 0.0.0.0:22              0.0.0.0:*               LISTEN      1235/sshd: /usr/sbi
tcp        0      0 192.168.1.18:840        192.168.1.254:2049      ESTABLISHED -
tcp        0      0 192.168.1.18:22         192.168.1.254:35720     ESTABLISHED 2274/sshd: root [pr
tcp    81280 2537798 127.0.0.1:36696         127.0.0.1:8080          ESTABLISHED 2184/./tools/io_uri
tcp    81482 2540160 127.0.0.1:8080          127.0.0.1:36696         ESTABLISHED 2184/./tools/io_uri
tcp6       0      0 :::22                   :::*                    LISTEN      1235/sshd: /usr/sbi

卡住直接原因同：http://hulk.rnd.huawei.com/issue/info/27030
req未完成，导致无法释放node
不同点在于这里操作的是"TCP"文件，在调用 sock_write_iter 时返回了 EAGAIN

__io_queue_sqe
 io_issue_sqe // 返回EAGAIN
  io_write
   call_write_iter
    sock_write_iter // Send-Q 满返回EAGAIN
 io_arm_poll_handler // 返回IO_APOLL_OK直接退出
  vfs_poll
   sock_poll
    sock_poll_wait
	 poll_wait // &sock->wq.wait
	  io_async_queue_proc
	   __io_queue_proc
	    add_wait_queue // io_uring的poll加入 sock 的链表中

预期情况：
网络层在接收到对端ack后从链表中取出poll，执行对应回调
io_poll_wake
 __io_poll_execute
  io_req_task_work_add

io_apoll_task_func
 io_req_task_submit
  __io_queue_sqe

实际情况：
网络层一直未接收到ack
```

```
tcp_rcv_established
  tcp_data_snd_check
    tcp_check_space
	  tcp_new_space
	    sk_stream_write_space

等sk_stream_write_space的EPOLLOUT事件
```


### folio
https://blog.csdn.net/feelabclihu/article/details/131485936


