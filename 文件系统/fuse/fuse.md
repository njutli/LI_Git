
### 用户态初始化流程
1. 定义参数
struct fuse_args args

2. 添加指定参数
fuse_opt_add_arg

3. 创建 fuse 实例
```
fuse_new // 包含参数 args 和定义的用户态文件系统操作集合 spinfs_ops
 fuse_new_fn
  _fuse_new_31
   calloc // 分配 fuse
   fuse_opt_parse // 解析 args 保存在 f->conf 中
   fuse_fs_new
    calloc // 分配 fuse_fs
	memcpy // spinfs_ops 保存在 f->fs->op 中
```

4. 挂载fuse
```
fuse_mount
 fuse_session_mount
  fuse_kern_mount
   fuse_mount_sys
    open // 打开 /dev/fuse
	mount // mo->subtype == "spinfs.bin"
  se->fd = fd // 保存 "/dev/fuse" 对应的 fd
```

5. 循环处理文件系统操作（以open请求为例）
```
fuse_loop
 fuse_session_loop
  fuse_session_receive_buf_internal
   _fuse_session_receive_buf
    read // 从 "/dev/fuse" 中读取请求，见"/dev/fuse"读取内核流程
<-------------------- 等待内核态写入请求并处理 -------------------->
  fuse_session_process_buf
   fuse_session_process_buf_internal
    do_open // fuse_ll_ops[in->opcode].func
     _do_open
      fuse_lib_open // req->se->op.open
       fuse_fs_open
	    spinfs_open // fs->op.open
<-------------------- 处理完成后写入结果并唤醒内核流程 -------------------->
       fuse_reply_open
	    fill_open // 将 fuse_file_info 填充至 fuse_open_out，包含用户态文件系统文件句柄信息
	    send_reply_ok
		 send_reply
		  send_reply_iov
		   fuse_send_reply_iov_nofree
		    fuse_send_msg
			 fuse_write_msg_dev
			  writev // 向 "/dev/fuse" 中写入结果，见"/dev/fuse"写入内核流程
```


### 内核挂载流程:

> mount("spinfs.bin", "/mnt/fuse_dir", "fuse.spinfs.bin", MS_NOSUID|MS_NODEV, "fd=15,rootmode=40000,user_id=0,group_id=0") = 0

```
do_mount // dev_name: spinfs.bin type_page:fuse.spinfs.bin
 path_mount
  do_new_mount
   get_fs_type
    strchr // 获取"."的位置
	__get_fs_type
	 find_filesystem
	  strncmp // 匹配已注册的文件系统名和"fuse.spinfs.bin"中的"fuse"
	 // 返回 fuse_fs_type
   // fs_flags 带有 FS_HAS_SUBTYPE
   strchr // 获取 subtype spinfs.bin
   fs_context_for_mount // 分配初始化 fs_context，带上 fs_type
    alloc_fs_context
	 fuse_init_fs_context // fc->fs_type->init_fs_context
	  // 分配初始化 fuse_fs_context
   vfs_parse_fs_string // 关键参数：
					   // subtype=spinfs.bin source=spinfs.bin fd=15(提前打开的/dev/fuse)
   vfs_get_tree
    fuse_get_tree // fc->ops->get_tree
	 get_tree_nodev // fuse_fill_super
	  vfs_get_super
	   sget_fc // 分配新的 superblock
	   fuse_fill_super // 初始化 superblock
	    fget // 获取 /dev/fuse 对应的 file
		kmalloc // 分配 fuse_conn
		kzalloc // 分配 fuse_mount
		fuse_conn_init // 初始化 fuse_conn
		fuse_fill_super_common
		 fuse_sb_defaults // 初始化 superblock
		 fuse_dev_alloc_install
		  fuse_dev_alloc // 分配新的 fuse_dev
		  fuse_dev_install // 关联 fuse_dev 和 fuse_conn
		 *ctx->fudptr = fud // 通过 fudptr 给 file->private_data 赋 fuse_dev
   do_new_mount_fc
```

> fuse_conn和fuse_mount
> 一对多，将 fm->fc_entry 加入 fc->mounts 链表关联
> 在挂载fuse时关联，在触发automount时关联？


### 内核open流程
```
do_sys_open
 do_sys_openat2
  do_filp_open
   path_openat
    do_open
	 vfs_open
	  do_dentry_open
	   fuse_open // f->f_op->open
	    fuse_open_common
		 fuse_do_open
		  fuse_file_alloc // 分配 fuse_file
		  fuse_send_open
		   fuse_simple_request
		    fuse_args_to_req // 设置 fuse_req
		    __fuse_request_send
			 queue_request_and_unlock
			  list_add_tail // 将 req 插入 fiq->pending 链表
			  fuse_dev_wake_and_unlock // fiq->ops->wake_pending_and_unlock
			   wake_up // 唤醒 fiq->waitq
<-------------------- 用户态读取"dev/fuse"获得请求并处理 -------------------->
			 request_wait_answer
			  wait_event // req->waitq 等待被唤醒
<-------------------- 用户态写"dev/fuse"返回结果并唤醒内核进程 -------------------->
		  ff->nodeid = nodeid // 保存打开文件信息
		  file->private_data = ff // fuse_file 保存在 file 中
```

在内核open流程唤醒fiq->waitq的同时，用户态进程阻塞在"/dev/fuse"的read流程中
```
// "/dev/fuse"读取内核流程
fuse_dev_read
 fuse_copy_init
  cs->iter = iter // 保存目标地址
 fuse_dev_do_read
  wait_event_interruptible_exclusive // fiq->waitq
  request_pending // 检测到 pending 链表上有请求
  fuse_copy_one
   fuse_copy_fill
    iov_iter_get_pages // 目标地址 cs->iter 转换成page --> cs->pg = page
   fuse_copy_do // 拷贝参数到 cs->pg 中
```

```
// "/dev/fuse"写入内核流程
fuse_dev_write
 fuse_dev_do_write
  fuse_copy_one // 拷贝用户态参数
  request_find // 获取 req
  fuse_request_end
   wake_up // req->waitq 唤醒阻塞的内核流程
``


### /dev/fuse 来源：
```
fuse_dev_init
 misc_register // fuse_miscdevice
```





https://blog.csdn.net/weixin_42792088/article/details/132862333
https://zhuanlan.zhihu.com/p/59354174
