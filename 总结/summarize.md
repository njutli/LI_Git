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
### （4）mysql
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
	Client(搭载spinfs，提供本地缓存目录) + Server(SSD存储container写入情况等日志数据 + 磁电盘存储归档数据)

spinfs结构：
	fuse + spinfs(overlayfs + erofs)

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
> 后台回写一般是超时或脏页超水线，或用户触发sync，保证数据落盘
> 对本地文件系统而言三者没有差别，对非本地文件系统有差别，例如nfs，超时或脏页超水线的回写只是writepages，保证数据发送到服务端，但sync会保证数据在服务端落盘
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
### （5）squashfs

Squashfs启用FILE_DIRECT方式读取磁盘数据，通过减少数据拷贝次数以及减少buffer锁冲突以提高读取速度，进一步提升系统启动性能及相关业务运行效率。

Squashfs是一种只读的压缩文件系统。该文件系统在存储文件时会选择性地将部分文件数据压缩后存储在磁盘上(使用指定压缩算法处理后所需空间减少则压缩后存储)。
Squashfs支持FILE_CACHE/FILE_DIRECT两种数据读取方式，主要差别在于FILE_CACHE方式在读取数据时会使用中间buffer。
相比较而言，FILE_DIRECT方式不使用中间buffer，可以减少了一次数据拷贝，提升启动性能，同时可以优化并发buffer锁冲突。

Squashfs两种数据读取方式的差异主要在于squashfs内部cache的使用，包括拷贝次数的差异以及buffer锁冲突的差异
1. 单进程单文件读取
该场景下读取的数据量较小，多一次拷贝对性能影响较小，同时buffer锁冲突概率低，两种数据读取方式的性能基本没有差距。
2. 多进程多文件读取
在drop cache后进行文件读取，性能瓶颈为读磁盘操作，两种方式的差异在于拷贝次数，由于内存拷贝速度较快，该场景下两种数据读取方式的性能差距较小；
在文件读取前不进行drop cache操作，在数据量较大的情况下FILE_CACHE数据读取方式的性能将会因buffer锁冲突概率升高而下降，该场景下两种数据读取方式的性能差距较大

> 技术限制<br>
从磁盘读取数据到page cache时，需确保page cache连续并可用。对于单个文件，由于多进程同时访问等原因，可能会出现该文件的page cache获取失败，或已处于uptodate状态，从而导致FILE_DIRECT方式不可用。<br>
相比较而言，由于FILE_CACHE使用Squashfs内部空闲的page cache，并且从内部page cache复制到文件page cache过程中若发现目标文件page cache不可用可以跳过，因此不存在该限制。<br>
综上，当目标文件page cache的page cache获取失败或处于uptodate状态时，FILE_DIRECT方式将退化成FILE_CACHE方式。


**功能设计**<br>
**Page Cache1：**<br>
Squashfs文件系统挂载时初始化的buffer，用于保存从磁盘读到的数据，所有读操作共享（Squashfs内部page cache）<br>
**Page Cache2：**<br>
读文件时初始化的buffer，用于保存从磁盘读到的文件数据，与特定文件关联（文件系统层page cache）<br>
**Actor：**<br>
包含Page Cache1及相关操作的集合，在文件系统挂载时初始化<br>
**Special Actor：**<br>
包含Page Cache2及相关操作的集合，在通过direct方式读取文件时初始化

<img width="508" height="300" alt="image" src="https://github.com/user-attachments/assets/6467178e-0ae4-4ab4-8eb1-cbd23368482d" />

squashfs支持FILE_CACHE/FILE_DIRECT两种数据读取方式。
1. FILE_CACHE：<br>
**磁盘→PageCache1**<br>
Squashfs文件系统挂载时会初始化用于read page的squashfs_cache，其中包含多个entry，每个entry都有一个对应的actor。PageCache1作为actor的成员，可由actor获取。
在从磁盘读取数据时，首先查找一个空闲的entry，并通过该entry的actor获取可用的PageCache1，然后通过BIO读取磁盘上对应的数据块，如果数据块已压缩，则解压后复制到PageCache1，否则直接复制到PageCache1。
在一个read page操作结束后可将entry释放给其他操作使用，从而实现了PageCache1的共享使用。
**PageCache1→PageCache2**<br>
PageCache1作为Squashfs内部缓存，用户态无法通过read系统调用直接访问，需将PageCache1中的内容复制到目标文件对应的缓存PageCache2中，再由内核将PageCache2中的数据传递到用户态。
2. FILE_DIRECT：<br>
**磁盘→PageCache2**<br>
与FILE_CACHE相比，FILE_DIRECT省略了PageCache1的使用，减少了一次数据复制，同时使用PageCache1所带来的锁冲突也可以消除，因此提升了读操作性能。但该方式也存在一定限制，需确保目标文件的page cache均可用（获取失败或处于uptodate状态均不可用），否则会退化成FILE_CACHE。由于FILE_CACHE使用Squashfs内部空闲的PageCache1，并且从PageCache1复制到PageCache2过程中若发现目标文件page cache不可用可以跳过，因此不存在该限制。
在从磁盘读取数据时，不再查找空闲entry，也不再使用文件系统挂载时初始化的actor及其关联的PageCache1。首先初始化一个special actor，之后查找目标文件的page cache，并将指针保存在special actor中，再通过BIO将磁盘数据直接读取到PageCache2，最后由内核将PageCache2中的数据传递到用户态。

## （四）块层
### （1）初始化
> 每个硬件队列对应一个tagset
> 初始化tagset的时候也会初始化对应的request tags->rqs tags->static_rqs ———— 这两个 rqs 集合怎么用？
> 
>	tags->static_rqs ———— request 指针数组，初始化的时候分配好对应的 request ，由调用者使用tag作为索引获取
> 
>	tags->rqs ———— request 指针数组，保存正在使用的 request

### （2）IO下发流程
见代码流程梳理

### （3）IO调度器

#### 1) elevator_mq_ops回调

1. 调度器生命周期管理

| 回调                    | 作用                                                                  |
| --------------------- | ------------------------------------------------------------------- |
| **`init_sched()`**    | 创建调度器实例时调用。分配调度器私有数据、初始化队列结构（如红黑树、heap）。                            |
| **`exit_sched()`**    | 销毁调度器时调用，释放资源。                                                      |
| **`init_hctx()`**     | 针对每个硬件队列 (Hardware Context, hctx) 初始化。比如在多核 NVMe 中每个 hctx 对应一个提交队列。 |
| **`exit_hctx()`**     | 清理对应 hctx 的调度数据结构。                                                  |
| **`depth_updated()`** | 当块队列深度（最大并发请求数）变化时调用，用于调度器调整内部参数（如 deadline 队列长度）。                  |

2. I/O 合并逻辑（merge path）

| 回调                      | 功能                                                |
| ----------------------- | ------------------------------------------------- |
| **`allow_merge()`**     | 判断某个 `bio` 是否允许与已有 `request` 合并。调度器可拒绝（例如按优先级区分）。 |
| **`bio_merge()`**       | 尝试直接把 `bio` 合并进已有 request（同 LBA 连续时），返回是否成功。      |
| **`request_merge()`**   | 尝试合并两个 request（back merge 或 front merge）。         |
| **`request_merged()`**  | 合并成功后通知调度器，可用于更新内部统计或 deadline。                   |
| **`requests_merged()`** | 当两个 request 被调度器内部主动合并时调用。                        |

3. 分配与准备阶段

| 回调                      | 功能                                    |
| ----------------------- | ------------------------------------- |
| **`limit_depth()`**     | 限制并发深度（队列可用 request 数）。调度器可动态调节，防止过载。 |
| **`prepare_request()`** | 在请求即将发往设备前准备，比如打上调度器 tag、设置截止时间。      |
| **`finish_request()`**  | 请求完成时调用，清理调度器内部状态或统计信息。               |

4. 请求插入与调度派发

| 回调                       | 功能                                             |
| ------------------------ | ---------------------------------------------- |
| **`insert_requests()`**  | 把一组新请求插入调度器队列（或重新插入的请求）。调度器决定放入哪个子队列、按什么排序。    |
| **`dispatch_request()`** | 从调度器中取出下一个要发往硬件队列的 request。返回 `NULL` 表示暂时无可派发。 |
| **`has_work()`**         | 快速判断调度器是否有待派发的工作，用于决定是否唤醒 dispatch loop。       |

5. 请求完成与重队列

| 回调                        | 功能                               |
| ------------------------- | -------------------------------- |
| **`completed_request()`** | 请求 I/O 完成后调用，可用于测量延迟、更新统计。       |
| **`requeue_request()`**   | 某请求由于错误或设备繁忙被退回时调用，调度器可重新安排其优先级。 |

6. 请求链辅助函数

| 回调                     | 功能                        |
| ---------------------- | ------------------------- |
| **`former_request()`** | 找到某 request 在调度队列中的前一个请求。 |
| **`next_request()`**   | 找到后一个请求。                  |

7. IO Context Queue (icq) 生命周期

| 回调               | 功能                                  |
| ---------------- | ----------------------------------- |
| **`init_icq()`** | 初始化 `io_cq`（例如分配 per-cgroup token）。 |
| **`exit_icq()`** | 清理 `io_cq`。                         |

#### 2) IO下发流程(经调度器)

```
submit_bio
 submit_bio_noacct
  submit_bio_noacct_nocheck
   __submit_bio_noacct
    __submit_bio
	 blk_mq_submit_bio
	  blk_mq_get_new_requests
	   __blk_mq_alloc_requests
	    data->rq_flags |= RQF_SCHED_TAGS // 有调度器的情况下使用调度器tag
		data->rq_flags |= RQF_USE_SCHED // 非 flush/passthrough 的req纳入调度器控制
		ops->limit_depth // 限制并发深度
		blk_mq_get_tag // 获取tag
		blk_mq_rq_ctx_init
		 e->type->ops.prepare_request // 设置调度器私有数据
	  blk_mq_insert_request
	   q->elevator->type->ops.insert_requests // 插入调度器队列
	  blk_mq_run_hw_queue
	   blk_mq_hw_queue_need_run // 根据硬件队列状态判断是否要下发req，如果是 quiesced 就直接返回，等待unquiesce下发？
	   blk_mq_run_dispatch_ops
	    blk_mq_sched_dispatch_requests
		 __blk_mq_sched_dispatch_requests
		  blk_mq_do_dispatch_sched
		   __blk_mq_do_dispatch_sched
		    e->type->ops.dispatch_request // 取出待下发的req
			blk_mq_dispatch_rq_list
			 q->mq_ops->queue_rq // 调用对应驱动的 queue_rq 回调下发req
```

#### 3) 常用调度器

1. mq-deadline — 有截止时间的公平调度

**核心思想：**  
`mq-deadline` 是 `deadline` 调度器在多队列架构 (`blk-mq`) 下的版本。  
它使用两个主要队列（按提交顺序和按截止时间排序），以防止请求饥饿。

**机制简述：**
- 每个 I/O 请求（读/写）都会有一个 **deadline（到期时间）**。
- 调度器优先派发最早到期的请求。
- 同时维护一个排序队列（按 LBA 升序）用于合并和顺序访问。
- 读请求优先级高于写请求。

**特点：**

| 项 | 描述 |
|----|------|
| 延迟 | 稳定且可控（deadline 机制） |
| 吞吐 | 中等偏高 |
| 公平性 | 较好（避免写饥饿） |
| 合并优化 | 支持 |
| CPU 开销 | 较低 |

**典型场景：**
通用服务器、数据库、桌面系统。

2. BFQ — Budget Fair Queueing

https://blog.csdn.net/hu1610552336/article/details/125862606?spm=1001.2014.3001.5502

**基本原理**
每个进程都先分配一个bfq调度队列bfq_queue，简称bfqq，bfqq与进程绑定。每个进程的bfqq分配一个初始配额budget，进程每派发一个IO请求，就消耗bfqq的一定配额(消耗的配额与传输的IO请求数据量成正比)。等bfqq的配额消耗光、bfqq上没有IO请求要要传输，则令bfqq到期失效。接着切换到其他进程的bfqq，派发这个新的bfqq上的IO请求。


**核心思想：**  
BFQ 为每个进程分配 I/O “预算”，以实现带宽公平与交互性。

**机制简述：**
- 每个进程或 cgroup 建立独立队列。
- 队列获得预算（可发的扇区数量）。
- 当预算耗尽或超时，切换队列。
- 优化顺序访问与合并。

**特点：**

| 项 | 描述 |
|----|------|
| 延迟 | 小请求响应性好 |
| 吞吐 | HDD 上高，SSD 上略低 |
| 公平性 | 最强 |
| CPU 开销 | 较高 |

**典型场景：**  
桌面系统人机交互不卡顿、多进程并发读写、HDD。

BFQ（Budget Fair Queueing）在块层为每个发起 I/O 的实体（进程/队列/cgroup）分配“预算”（按字节/扇区计的服务时间），队列按权重在一个公平队列算法（类似 WF2Q+ 的虚拟时间/虚拟完成时间）中竞争磁盘服务：每次选择虚拟完成时间最小的队列运行，直到其预算用尽或变空，并自适应调整预算以兼顾顺序合并与切换开销。同时它识别交互/软实时的小 I/O 流，支持抢占与快速启动，降低前台延迟；最终实现带宽按权重的近似公平分配，并在 HDD/低并发场景中提升交互流畅性。

3. Kyber — 低延迟高并发调度器

**核心思想：**  
专为 NVMe/SSD 设计的调度器，基于 token bucket 控制并发深度。

**机制简述：**
- 维护独立的读/写 token。
- 限制并发请求数，防止过载。
- 动态调节 token 平衡延迟与吞吐。

**特点：**

| 项 | 描述 |
|----|------|
| 延迟 | 极低、稳定 |
| 吞吐 | 极高 |
| 公平性 | 一般 |
| CPU 开销 | 低 |

**典型场景：**  
数据中心、高并发数据库、NVMe 存储。

Kyber 的核心是“按请求类别的并发窗口控制 + 目标延迟反馈”。它把 I/O 按读、同步写、异步写等分成服务类，为每类设置可并发下发到设备的令牌数量（队列深度），只有拿到令牌的请求才会进入设备队列，从而避免深队列导致的尾延迟膨胀；同时以读优先并根据观测到的完成延迟动态调节各类窗口大小，在保持低且稳定的读延迟的前提下尽量维持吞吐。由于不做复杂的重排序/合并，Kyber 在 NVMe/SSD 上以极低 CPU 开销实现较好的延迟-吞吐折中。

4. None（Noop）— 直通型调度器

**核心思想：**  
不做排序或合并，直接交给设备。

**特点：**

| 项 | 描述 |
|----|------|
| 延迟 | 最低 |
| 吞吐 | 取决于设备 |
| 公平性 | 无 |
| CPU 开销 | 最低 |

**典型场景：**  
NVMe SSD、虚拟化磁盘、RAM disk。

none 调度器存在的原因是：在现代 SSD、NVMe 等设备上，硬件自身已具备高效的并行队列和调度能力，软件再排序只会增加延迟与 CPU 开销。none 提供一个最简路径——直接将 I/O 请求交给设备，同时**保持块层架构的一致性**，因此在不需要软件调度时，它是最合理、最高效的选择。

### （4）IO限速的方式及目的

在IO下发路径中，会通过throttle（例如wbt），get tag，get budget，plug，还有调度器限制IO的下发。这些“限制/门控”存在的共同目的，是在复杂、多租户、多层缓存/写回的系统里，让块设备始终工作在可控、可预测、效率较高的区间，避免失控的排队、抖动与放大。不同环节针对的问题和目标各不相同，但核心诉求包括：控制队列深度、保护读延迟、抑制写放大/写洪峰、维持设备/缓存的稳态、实现公平/隔离、提升合并率与吞吐、以及配合电源/寿命与系统整体稳定性。

#### I/O 下发路径中的典型限制与目的

1. throttle（如 wbt：Writeback Throttling）
- 目的：限制页缓存写回对存储的瞬时压力，保证读优先与交互延迟，防止脏页骤然刷盘导致设备长时间被写占满。
- 手段：根据设备时延反馈或目标延迟，动态限制写入速率/并发，使设备排队保持在健康水平。

2. get tag（blk-mq 硬件/驱动队列标签）
- 目的：控制真正在设备上的飞行请求数，使之不超过控制器/队列可承受的并发；避免硬件队列深度过大导致尾延迟膨胀或固件退化。
- 手段：下发前获取 tag，耗尽则阻塞或排队，形成对设备层的硬性并发上限。

3. get budget（调度器预算）
- 目的：在软件层按权重/类别公平分配磁盘服务时间或并发额度，避免单流独占，保护低延迟流；同时将并发控制在调度器目标之内。
- 手段：消费预算才能继续下发；预算随完成/行为自适应调整。注：BFQ 的“字节预算”、Kyber 的“窗口/令牌”本质都属预算控制。

4. plug（I/O plugging）
- 目的：短暂聚合上层提交的多个请求，增加合并与排序机会，减少寻道/提交开销，提升吞吐。
- 手段：在进程上下文中暂不立刻下发，等到拔塞（unplug）时批量提交。过长会放大延迟，需权衡。
plug
针对一个大bio拆分出的多个小bio对应的多个request，在request达到一定数量后再下发，避免每分配一个request就下发一次

5. 调度器限制（kyber/mq-deadline/BFQ/none）
- 目的：在“软件队列 → 硬件队列”之间施加策略性控制：读优先、有界延迟、公平隔离、顺序性/合并等，根据设备类型与负载目标优化整体表现。

#### 为什么要多层限制

- 职责分离、不同反馈粒度  
  写回节流关注页缓存层的脏页与系统级背压；调度器/plug 关注块层排序与公平；tag 关注硬件并发边界。这些层次的信息与作用域不同、互补。

- 组合稳定性  
  单点控制难以在所有负载下稳住延迟与吞吐，多层门控让系统在不同环节都不“过载”，减少级联放大（如写回洪峰 → 块层深队列 → 固件拥塞 → 超时重试）。

- 适配多设备与多工作负载  
  HDD、SATA SSD、NVMe 行为差异大；在线读敏感 vs. 批量吞吐目标不同，需要可叠加的策略。

#### 除此之外的其他限制/机制

- cgroup I/O 控制  
  io.max、io.weight（cgroup v2），限制带宽/IOPS或按权重分配，实现多租户隔离与保护。

- 文件系统与写回策略  
  vm.dirty_ratio/dirty_bytes、dirty_background_*、balance_dirty_pages（写回节流）、FUA/Barrier 顺序点，控制脏页生成与落盘节奏，保障一致性并抑制洪峰。

- 合并与排序限制  
  合并阈值、requeue 策略、bio 合并开销上限；避免过度 CPU 花费或无效重排序。

- 设备/驱动层限制  
  队列深度（queue_depth）、nr_requests、ZNS 顺序写约束、SCSI/NVMe 超时与错误恢复、NCQ/TCQ 限幅等。

- 电源与热/寿命保护  
  设备内部热限制/写放大控制、主机端 runtime PM、NVMe APST；防止持续高并发导致过热或寿命快速消耗。

- NUMA/亲和与软中断背压  
  blk-mq 队列与 CPU/IRQ 亲和绑定、软中断 NAPI/backpressure，避免跨节点争用引发额外尾延迟。

- 上层应用限流  
  数据库/存储服务自控并发（如每卷 QD 限制）、token bucket、排队中间件，以业务 SLA 为目标做端到端节流。

- 错误与拥塞反馈  
  超时重试、IO 优先级（ionice）、调度器自适应参数（如 Kyber 的目标延迟反馈、mq-deadline 的读优先窗口）。

### （5）rq-qos
见blk-cgroup框架.md


## （五）驱动层
### dm
#### dm-snapshot

**基本使用流程：**
1. 创建dm-linear设备base；
2. 向base中写入数据；
3. 创建快照
创建dm-linear设备base-real，映射关系同base；
将base设备reload成snapshot-origin类型，一比一映射在base-real上（保证后续对base设备下发IO会触发快照的同步）；
创建dm-linear设备snap-cow；
创建snapshot设备snap，load到base-real和snap-cow上
4. 向base设备下发读写IO
读IO直接下发到实际物理设备上；
写IO会先将对应位置的数据备份到cow设备上，再修改实际物理设备上的数据，保证从snap读到的数据不变（因此打完快照后，对某个区域的第一次写，会触发一次备份，影响性能）
5. 使用snap恢复base中的数据

**可提升点：**
1. snapshot失效后可恢复成有效状态
将snapshot作为实际的存储设备，一旦出现cow溢出或者IO错误，会导致设备失效变只读，且再也无法恢复，限制使用场景
2. base设备在打快照后大量数据写的场景，不备份，改映射
备份数据有读写开销，对于大量数据的修改，让snapshot直接映射到原数据区域，新写入数据写到另一个块区域，再映射给base设备（需要给base设备增加映射管理机制）

### SCSI
iscsi target使用流程：
https://gitee.com/openeuler/kernel/issues/I8ZUL1
### NVME

## （六）竞争力
### （1）文件系统I/O Write zero优化
背景
高斯数据库预写场景，期望能够在正常文件系统使用期间尽可能减少元数据IO给数据IO带来的性能影响

具体场景
1. ext4 osync挂载方式
2. 数据库文件批量预分配，单文件几兆~几十兆一个，后续运行过程中通过覆盖写方式运行
3. 业务单次修改数据量不大只有几KB~十几KB，在Osync模式下元数据IO占比很大，开销无法忽略，需要降低元数据IO

现有解决方案与问题
1. 文件预分配通过全量写入文件的方式创建（dd写），预分配耗时长；
2. 如果使用fallocate方式分配，后续覆盖写的过程中会涉及到extent转换，进而还是会存在大量元数据IO；
3. 批量预分配的文件可能会在运行过程中不够，需要扩展，扩展也会引起IO抖动

具体诉求
1. 使用fallocate进行预分配，但不能耗时过长
2. 后续覆盖写预分配的文件不能产生大量的元数据IO

为fallocate新增一个MODE (FALLOC_FL_WRITE_ZEROES)，利用磁盘的write zero cmd加速批量预留写0操作，产生的预留文件是written状态的，在后续实际写入时结合datasync可以消除所有同步的日志I/O和元数据I/O。
https://lore.kernel.org/all/20250619111806.3546162-1-yi.zhang@huaweicloud.com/

### （2）文件系统在线元数据一致性检测和细粒度隔离技术
背景
构建文件系统在线元数据一致性检测和细粒度隔离技术，细粒度单点隔离元数据异常，避免文件系统整体拒绝访问，降低文件系统异常重启恢复率50%

通过 dm-snapshot 为文件系统创建快照，基于快照进行fsck扫描，根据扫描结果将 bad inodes 标记为 corrupted

### （3）大IO拆分 + 并发场景，顺序IO变随机导致性能劣化

背景
从sq切换成mq后，在多个进程同时下发IO场景，mq无法再保证大IO拆分出来的顺序IO连续下发到设备，将导致性能劣化。

目前通用块层已支持批量申请与下发requests，目前只有io_uring使用。在io拆分场景应用 request批量申请与下发 解决该场景性能问题；

### （4）~~云场景加速全零写入~~

背景
云场景存储LUN使用网盘，在不使用dm-crypt时，在导入镜像时可以跨过全零的块，只写有数据的部分即可。但在使用dm-crypt时，为保证数据加解密正常，全零数据也需要写入（防止磁盘上的脏数据影响解密），从而降低了数据读写效率。
当前采用基于dm-zero + dm-snapshot创建dm-crypt的方式构建稀疏盘(dm-snapshot基于dm-zero和cow设备创建，dm-crypt基于dm-snapshot创建，从dm-crypt下发的写IO，数据为零则和dm-zero返回的数据匹配，不发生任何写入，数据非零则向cow设备中写入数据与映射)，从而去除了读写全零数据的动作，写入时仅加密并写入非零数据，读取时只读出并解密非零数据，提高了读写效率。

**dm-snapshot在IO错误或cow溢出时会失效，且无法恢复**


## （七）其他
### （1）io_uring
#### 1) io_uring基本结构和流程
##### 1.1) 基本结构
<img width="724" height="841" alt="image" src="https://github.com/user-attachments/assets/bb739a91-4c18-496d-a465-594ad5a5311b" />
<img width="724" height="630" alt="image" src="https://github.com/user-attachments/assets/087cd837-cd88-4f2c-9fdd-04e3d11a5b1f" />
<img width="242" height="763" alt="image" src="https://github.com/user-attachments/assets/e6e9dc8d-5881-42cd-9f7c-bb92e5963dfd" />
<img width="430" height="833" alt="image" src="https://github.com/user-attachments/assets/c553d908-0eec-43a3-a34a-497cf9eb7155" />

##### 1.2) 关键流程
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
**IO下发方式**<br>
1、同步下发<br>
普通的读写请求下发后是同步下发，请求也可能变成异步下发，例如请求结果是 -EAGAIN，同时请求没有 REQ_F_NOWAIT 标记<br>
2、异步下发<br>
1) 用户态指定异步标记 REQ_F_FORCE_ASYNC
2) 普通请求失败重试
3) 特殊请求，如 IORING_OP_ASYNC_CANCEL

**worker 线程**
处理异步下发的请求，空闲链表上有可用worker则使用，否则新建 io_wqe_worker

#### 2) io_uring高级特性
##### 2.1) SQPOLL<br>
适用于高频小IO的场景
让内核端有一个专用的内核线程持续轮询 SQ（Submission Queue），从而消除用户态→内核态提交 I/O 时的系统调用开销
在普通 io_uring 模式下，用户每次提交 I/O 都需要调用一次：
```
io_uring_enter(ring_fd, to_submit, min_complete, flags, sigset)
```
这仍然是一次系统调用。
而启用 IORING_SETUP_SQPOLL 后，用户空间线程只需将 SQE 写入共享的 ring buffer，
内核态的 poll 线程（sq thread） 会主动从 ring 中取出请求并提交到内核 I/O 栈中，无需用户态系统调用唤醒内核。

**SQPOLL 线程创建：**<br>
当创建 io_uring 实例时，如果 params.flags 设置了 IORING_SETUP_SQPOLL，内核会为该 ring 创建一个 专用内核线程（sq thread） 来轮询 SQ。

**SQPOLL 线程生命周期：**<br>
线程启动：创建 ring 时由内核 io_sq_thread 创建；
线程常驻内核，不退出；
如果超过 sq_thread_idle（默认 2s）未检测到新请求，会自动休眠；
当用户下次调用 io_uring_enter(..., IORING_ENTER_SQ_WAKEUP, ...) 时再唤醒。

**性能收益分析：**<br>
避免系统调用：提交请求时无需 io_uring_enter()，减少 syscall 开销；
批量处理更高效：SQPOLL 线程能一次性取多个 SQE 进行提交；
CPU cache 亲和性好：SQPOLL 线程通常绑定在特定 CPU 核上（SQPOLL线程和下IO线程在同一NUMA上）

**Redis特殊需求**<br>
为保证性能，在启动sq_poll的情况下，需要io_uring-sq线程与redis进程始终在同一NUMA上运行。
在拉起io_uring-sq线程时，继承调用者redis进程的cpumask。由于io_uring-sq线程只会初始化一次cpumask，当redis被重新调度时，由调度模块保证同NUMA，io_uring则保证io_uring-sq线程可由用户态设置cpumask，回合补丁 a5fc1441af77 ("io_uring/sqpoll: Do not set PF_NO_SETAFFINITY on sqpoll threads")

| 特性      | SQPOLL                   | IOPOLL                |
| ------- | ------------------------ | --------------------- |
| 全称      | Submission Queue Polling | IO Completion Polling |
| 线程      | 独立内核线程轮询 SQ              | 用户态主动轮询 CQ            |
| 减少的系统调用 | 提交 syscall               | 完成 syscall            |
| CPU 占用  | 持续运行，可能较高                | 可控制轮询时间               |
| 典型场景    | 高频提交、低延迟                 | NVMe 驱动、极低延迟 I/O      |

**使用限制：**<br>
5.11前所有 I/O 必须使用注册文件（Registered Files），5.11消除了这个限制
https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?id=28cea78af44918b920306df150afbd116bd94301

**之前 SQPOLL 必须使用固定文件的原因：**<br>
SQPOLL 线程是独立的内核线程，运行在不同的进程上下文中
普通文件描述符（fd）是进程相关的，通过 current->files 访问
SQPOLL 线程的 current->files 为 NULL，无法解析用户提交的 fd

```
+static void __io_sq_thread_acquire_files(struct io_ring_ctx *ctx)
+{
+       if (!current->files) {
+               struct files_struct *files;
+               struct nsproxy *nsproxy;
+
+               task_lock(ctx->sqo_task);
+               files = ctx->sqo_task->files;
+               if (!files) {
+                       task_unlock(ctx->sqo_task);
+                       return;
+               }
+               atomic_inc(&files->count); // 增加引用计数
+               get_nsproxy(ctx->sqo_task->nsproxy);
+               nsproxy = ctx->sqo_task->nsproxy;
+               task_unlock(ctx->sqo_task);
+
+               task_lock(current);
+               current->files = files; // 保留请求提交进程的 files_struct
+               current->nsproxy = nsproxy;
+               task_unlock(current);
+       }
 }
```

##### 2.2) IOPOLL
```
**传统模式：**
提交 I/O → 等待 → 硬件中断 → 中断处理 → 唤醒进程 → 收割完成事件
        ↑_______________________________________|
                    上下文切换开销

**IOPOLL 模式：**
提交 I/O → 主动轮询设备状态 → 发现完成 → 立即处理
                ↑________________|
                   无中断，低延迟
```
启用 IOPOLL 的情况下，需要用户发起查询请求后，内核向驱动层发起 poll 请求，驱动再向设备发 poll 请求，确认 IO 完成后内核才会填充 CQ。如果用户不调用 poll，内核不会主动感知 I/O 完成，也不会触发 CQ 填充

##### 2.3) register file
适用于高频小IO的场景
允许应用一次性注册一批 file 对象到内核，从而避免每次 I/O 都查 fd 表
```
**传统方式（每次 I/O）：**
用户 fd → fget(fd) → 文件表查找 → 引用计数++ → 使用 → fput() → 引用计数--
         ↑______________________________________________|
              每次都要加锁、查表、操作引用计数

**Register File 方式：**
注册阶段：fd → 构建索引数组 → 增加引用计数（一次性）
使用阶段：index → 直接数组访问 → 使用文件（无锁、无查表）
                    ↑_______________|
                   O(1) 直接访问
```
**基本机制：**<br>
应用通过
```
io_uring_register(ring_fd, IORING_REGISTER_FILES, files, nr_files);
```
把一组文件描述符注册到 io_uring 的上下文中（io_ring_ctx）。
内核会：
把每个用户态 fd 转换成 struct file *；
存入 ctx->file_data[]；
为这些 file * 增加引用计数；
后续 I/O 直接引用这些 file 指针。
注册成功后，提交 SQE（submission queue entry）时不再使用 fd 字段，而改用：
```
sqe->flags |= IOSQE_FIXED_FILE;
sqe->fd = registered_index;   // 对应注册数组下标
```
这样，在提交阶段，io_uring 不再执行 fget() 查表，而是：
```
req->file = ctx->file_data[sqe->fd];
```
直接获取到已缓存的 struct file *。

**性能收益分析：**
| 路径       | 标准 I/O           | 使用 register files |
| -------- | ---------------- | ----------------- |
| 每次提交 I/O | fget() + fput()  | 直接取缓存指针           |
| 系统调用     | 每次 1 次           | 通常批量提交            |
| 内核锁竞争    | 有 (files_struct) | 无                 |
| 上下文切换    | 较多               | 可完全避免             |

##### 2.4) register buffer
适用于高频小IO的场景<br>
https://zhuanlan.zhihu.com/p/12853597708<br>

**为什么需要 register buffer ———— 减少处理用户地址的开销**<br>
在常规 I/O（包括普通 read/write 或 DIO）中，内核要处理用户空间的缓冲区指针：
<ol>
<li>检查用户地址是否合法（access_ok()）；
<li>分页映射：get_user_pages()（GUP）；
<li>建立页表映射或拷贝数据；
<li>I/O 完成后 put_page() 释放引用。
</ol>

这些步骤在高频小块 I/O 中开销非常大。
如果同一批缓冲区被频繁复用（例如数据库读写页缓存、网络 ring buffer），
不断做 GUP / unmap 是完全没必要的。
于是：
> io_uring 提供了“register buffer”机制，允许应用**一次性注册一批内存页到内核中，之后反复使用这些 buffer 进行零拷贝 I/O**。

**基本机制：**<br>
应用通过：
```
struct iovec iovecs[nr];
io_uring_register(ring_fd, IORING_REGISTER_BUFFERS, iovecs, nr);
```
每个 iovec 描述一块用户空间缓冲区：
```
struct iovec {
    void *iov_base; // 起始地址
    size_t iov_len; // 长度
};
```
注册时内核会：
<ol>
<li>检查缓冲区可访问；
<li>将用户空间虚拟地址锁定（防止被换出）；
<li>调用 pin_user_pages() 将其固定为物理页；
<li>保存到 io_ring_ctx->user_bufs[]；
<li>维护引用计数，直到 unregister。
</ol>

**使用方式**<br>
注册成功后，提交 SQE 时：
```
sqe->buf_index = index;             // 指向注册的 buffer
sqe->flags |= IOSQE_BUFFER_SELECT;  // 或通过 BUFFER_GROUP 动态选择
```
这样 I/O 不再传递 `addr` 字段，而是直接引用已注册的缓冲区。

> &#9888; register buffer 的缓冲区需要页对齐（page aligned）
>> 1. 内核通过“页粒度”来固定（pin）用户空间内存，如果缓冲区不是页对齐的，首尾两页就需要特殊处理，这会导致多页被部分 pin，带来复杂性和性能损失。
>> 2. man 手册没有要求“注册缓冲必须页对齐”。非页对齐可以注册，但会按页粒度 pin，造成更多内存被锁定；若对 O_DIRECT 路径做 I/O，不对齐会导致 I/O 本身失败（通常 EINVAL）。为性能与稳健，建议按页甚至设备扇区对齐分配并注册缓冲区。

#### 3) 优化实践
SQPOLL+register file优化redis性能

#### 4) io_uring问题

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


### （八）folio
https://blog.csdn.net/feelabclihu/article/details/131485936

### （九）workqueue
https://www.cnblogs.com/arnoldlu/p/8659988.html

https://www.cnblogs.com/zxc2man/p/6604290.html

[kworker/u515:4-flush-253:4]这个不是进程名，是在 wq_worker_comm 里拼接出来的 task->comm + worker->desc

一般是在process_one_work()这里通过strscpy设置workqueue的名字到worker->desc里
当前这个是在wb_workfn()这里是通过set_worker_desc()把“flush-%s”加设备号设置到desc里

中断上下文和进程上下文

https://www.cnblogs.com/stemon/p/5148869.html

workqueue的基本概念

http://www.wowotech.net/irq_subsystem/workqueue.html

CMWQ概述

http://www.wowotech.net/irq_subsystem/cmwq-intro.html

创建workqueue代码分析

http://www.wowotech.net/irq_subsystem/alloc_workqueue.html

workqueue如何处理work

http://www.wowotech.net/irq_subsystem/queue_and_handle_work.html

Linux Workqueue到CMWQ的技术演进

https://blog.csdn.net/rikeyone/article/details/100710920

### （十）cgroup
#### （1）相关结构
blkcg	(struct blkcg)
	表示cgroup中blkio子系统中的一个control group，体现给用户是一个目录
blkg	(struct blkcg_gq)
	用来关联blkcg和request_queue(被控制的设备)
blkcg_policy	(struct blkcg_policy)
	控制策略，当前不支持以模块的形式，只在系统初始化注册

#### （2）相关流程
**blkcg**
-	创建：
-		blkcg_css_alloc
```
// 创建根cgroup根目录
cgroup_init
 cgroup_setup_root
  rebind_subsystems
   cgroup_apply_control
    cgroup_apply_control_enable
	 css_create
	  blkcg_css_alloc // ss->css_alloc
	   blkcg = kzalloc
	   pol->cpd_alloc_fn // 遍历处理所有已注册的控制策略

// 创建子cgroup子目录
cgroup_mkdir
 cgroup_apply_control_enable

```

-	删除：
-		blkcg_css_free
```
// cgroup 目录释放
css_release
 css_release_work_fn
  css_free_rwork_fn
   blkcg_css_free // ss->css_free
    blkcg_policy[i]->cpd_free_fn // 遍历处理所有已注册的控制策略
```

**blkg**
-	创建：
-		blkg_alloc
```
// 初始化 request_queue
blk_alloc_queue
 blkcg_init_queue
  blkg_alloc
  q->root_blkg = blkg

// 写控制策略对应的文件
bfq_io_set_weight
 bfq_io_set_device_weight
  blkg_conf_prep
   blkg_alloc

// 初始化bio的时候创建
bio_associate_blkg_from_css
 blkg_tryget_closest
  blkg_lookup_create
   blkg_create
    blkg_alloc
```

-	删除：
-		blkg_free
```
// cgroup删除和删盘，blkg 计数减到0
blkg_release
 __blkg_release
  blkg_free
```

**blkg-blkcg关联**

bio_init
 bio_associate_blkg
  bio_associate_blkg_from_css

bio初始化时，会根据**设备 + 进程**关联一个唯一的blkg，实现在bio_associate_blkg()

#### （3）已知问题：
##### 3.1) 除0异常
在X86平台，使用mul_u64_u64_div_u64()对用户态传递的IO限制与当前IO经历的时间相乘，再除以HZ，若结果溢出，会触发除0异常
https://lore.kernel.org/all/CACGdZYLFkNs7uOuq+ftSE7oMGNbB19nm40E86xiagCFfLZ1P0w@mail.gmail.com/

**cgroup v1接口**
```
blkio.throttle.read_bps_device
blkio.throttle.write_bps_device
```

**cgroup v2接口**
```
io.max // rbps wbps
```

**示例**
```
// cgroup v1
mount /dev/sda /home/io_test/
mkdir -p /sys/fs/cgroup/blkio/throt
echo "8:0 1000" > /sys/fs/cgroup/blkio/throt/blkio.throttle.write_bps_device
echo $$ > /sys/fs/cgroup/blkio/throt/cgroup.procs
dd if=/dev/zero of=/home/io_test/test.file bs=1M count=10 oflag=direct &
echo "8:0 8375319363688624583" > /sys/fs/cgroup/blkio/throt/blkio.throttle.write_bps_device

// cgroup v2
cd /sys/fs/cgroup/
mkdir blktest
cd blktest/
echo "8:0 rbps=2" > io.max
echo $$ > cgroup.procs
dd if=/dev/sda of=/dev/null bs=4k count=1024 iflag=direct &
echo "8:0 rbps=8375319363688624583" > io.max
```

##### 3.2) 删除cgroup后IO长时间无法完成

进程切换cgroup前下发的IO一直被原cgroup阻塞，切换cgroup后，原cgroup删除后，用户态的接口已经删除，但内部的数据结构仍被未完成的IO给ping住，从而导致IO一直被限制，但解除限制的接口已经不存在，无法解除限制

```
cgroup_rmdir
 cgroup_destroy_locked
  kill_css // 子系统下线
   percpu_ref_kill_and_confirm
    ...
    css_killed_ref_fn
     css_killed_work_fn
      offline_css
       blkcg_css_offline // ss->css_offline
        blkcg_unpin_online
         blkcg_destroy_blkgs
          blkg_destroy
           throtl_pd_offline // pol->pd_offline_fn throttle的offline回调只清除了LIMIT_LOW的限制
  kernfs_remove // 删除 kernfs 目录

目录删除后并不会清除blkg，因此对IO的限制仍然存在
具体体现为 tg->disptime 较大，当前时间未达到，不会下发IO，且由于目录删除，无法修改限制，也无法触发 disptime 的更新，导致IO一直被限制
```

```
cd /sys/fs/cgroup/
mkdir blktest
cd blktest/
echo "8:0 rbps=2" > io.max
echo $$ > cgroup.procs
dd if=/dev/sda of=/dev/null bs=4k count=1024 iflag=direct &
cd ..
echo $$ > cgroup.procs
echo 2783 > cgroup.procs # dd进程
rmdir blktest
```

### （十一）性能相关

#### (1)性能案例

查看磁盘调度器
cat /sys/block/sda/queue/scheduler

查看磁盘配置
find /sys/block/sda/ -type f | xargs grep .

```
1、确认IO是否是关键路径
1) iostat -x 10 // 以10秒为周期查看带宽，可以适当延长周期
2) 通过cgroup限速
// cgroup v1
mkdir -p /sys/fs/cgroup/blkio/throt // 创建新的blk-cgroup
echo "8:0 1000" > /sys/fs/cgroup/blkio/throt/blkio.throttle.read_bps_device // 设置磁盘和IO限制，IO限制设置为iostat查询到的稳定带宽的90%
echo $$ > /sys/fs/cgroup/blkio/throt/cgroup.procs // 将下发IO的进程加入控制，"$$"改为对应进程号
// cgroup v2
mkdir -p /sys/fs/cgroup/blktest
echo "8:0 rbps=2" > /sys/fs/cgroup/blktest/io.max
echo $$ > cgroup.procs
3) IO下发完成后查看TPS是否有明显变化

2、查看盘配置（IO确认是关键路径）
find /sys/block/sda/ -type f | xargs grep .
查看磁盘配置并对比

3、blktrace抓取IO耗时情况（IO确认是关键路径，且配置无差异）
通过iostat观察带宽是否稳定，在带宽稳定的情况下抓取IO耗时情况（通过延长测试时间或其他业务层手段保证带宽稳定）

sh bmetric.sh -s Q 418.log > 418_Q_res.log
sh bmetric.sh -s D 418.log > 418_D_res.log
sh bmetric.sh -s Q 510_none.log > 510_none_Q_res.log
sh bmetric.sh -s D 510_none.log > 510_none_D_res.log

过滤Q-C和D-C的数据对比
```

#### (2)iostat

https://www.cnblogs.com/ggjucheng/archive/2013/01/13/2858810.html
https://bean-li.github.io/dive-into-iostat/

mq模式中，内核主要在两个地方进行IO统计
开始时，blk_mq_bio_to_request() 中调用 blk_account_io_start
结束时，blk_mq_end_request() 中调用 blk_account_io_done 和 blk_account_io_completion

bio模式中
__part_start_io_acct
__part_end_io_acct

#### (3)wbt
在不影响读操作和同步写操作的前提下，通过对后台写入请求进行自适应节流，在系统的延迟和吞吐量之间取得平衡。
1.延迟控制：确保读操作的延迟符合预设的目标，防止异步写和discard请求挤占资源。
2.并发限制：通过动态控制并发异步写和discard请求数，避免异步写操作导致的队列积压，从而减少对系统资源的过度占用。

1.	整体实现思路
写回节流（WBT）机制的核心是基于延迟监控和队列深度调节的动态节流机制。根据请求类型进行节流决策，其实现思路如下：
−	延迟监控：WBT通过监控每个窗口中的最小延迟时间，并与延迟目标进行比较来判断延迟是否超标。如果判断超标，则调整请求队列深度。主要针对读请求。
−	并发控制：WBT通过跟踪每个等待队列的活动IO数量，并与节流限制进行比较，以控制后台写回请求的并发数量。主要针对异步写、discard请求。
2.	调试手段
通过debugfs输出调试信息，记录每个窗口周期内的延迟、队列深度、缩放规则等信息，便于性能调优和监控。
/sys/kernel/debug/block/<bdev_name>/rqos/wbt

<img width="528" height="274" alt="image" src="https://github.com/user-attachments/assets/af64ca32-7e82-4136-9abe-62130b3fb0ca" />

<img width="531" height="333" alt="image" src="https://github.com/user-attachments/assets/ecd41a23-4f3a-4600-a7d7-8f7dc4acf87b" />

<img width="530" height="550" alt="image" src="https://github.com/user-attachments/assets/41f29c72-d68c-4fe2-b356-f00ccc9bf2f6" />

<img width="532" height="471" alt="image" src="https://github.com/user-attachments/assets/6a78faf0-a3e4-49e5-9248-bfb5633aabfe" />



