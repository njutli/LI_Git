
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
	memcpy // spinfs_ops 保存在 fs->op 中
```

4. 挂载fuse
```
fuse_mount
 fuse_session_mount
  fuse_kern_mount
   fuse_mount_sys
    open // 打开 /dev/fuse
	
```


5. 循环处理文件系统操作
fuse_loop



### /dev/fuse 来源：
```
fuse_dev_init
 misc_register // fuse_miscdevice
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






https://blog.csdn.net/weixin_42792088/article/details/132862333
https://zhuanlan.zhihu.com/p/59354174
