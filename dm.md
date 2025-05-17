
# 一、文档

官方文档

https://docs.kernel.org/admin-guide/device-mapper/thin-provisioning.html

基本用法和snapshot

https://icloudnative.io/posts/devicemapper-theory/

https://coolshell.cn/articles/17200.html

磁盘布局

https://www.cnblogs.com/boring-codeer/p/6187879.html

其他

http://froghui.github.io/device-mapper/

# 二、dm-linear

```c
dmsetup create test --table "0 20971520 linear /dev/sda 0"
dmsetup suspend test
dmsetup reload test --table "0 2048 linear /dev/mapper/test 0"
dmsetup resume test

[2023-01-29 20:26:44]  [18131.412558]  dm_dax_supported+0x5b/0xa0
[2023-01-29 20:26:44]  [18131.412559]  dax_supported+0x28/0x50
[2023-01-29 20:26:44]  [18131.412559]  device_not_dax_capable+0x45/0x70
[2023-01-29 20:26:44]  [18131.412560]  ? realloc_argv+0xa0/0xa0
[2023-01-29 20:26:44]  [18131.412560]  linear_iterate_devices+0x25/0x30
[2023-01-29 20:26:44]  [18131.412561]  dm_table_supports_dax+0x42/0xd0

struct dm_table {
        struct mapped_device *     md;                   /*     0     8 */
        enum dm_queue_mode         type;                 /*     8     4 */
        unsigned int               depth;                /*    12     4 */
        unsigned int               counts[16];           /*    16    64 */
        /* --- cacheline 1 boundary (64 bytes) was 16 bytes ago --- */
        sector_t *                 index[16];            /*    80   128 */
        /* --- cacheline 3 boundary (192 bytes) was 16 bytes ago --- */
        unsigned int               num_targets;          /*   208     4 */ 当前target个数
        unsigned int               num_allocated;        /*   212     4 */ 已分配的总target空间
        sector_t *                 highs;                /*   216     8 */ dm设备的地址范围
        struct dm_target *         targets;              /*   224     8 */ 与dm设备地址范围对应的target设备信息
        struct target_type *       immutable_target_type; /*   232     8 */
        bool                       integrity_supported:1; /*   240: 0  1 */
        bool                       singleton:1;          /*   240: 1  1 */
        unsigned int               integrity_added:1;    /*   240: 2  4 */

        /* XXX 29 bits hole, try to pack */

        fmode_t                    mode;                 /*   244     4 */
        struct list_head           devices;              /*   248    16 */
        /* --- cacheline 4 boundary (256 bytes) was 8 bytes ago --- */
        void                       (*event_fn)(void *);  /*   264     8 */
        void *                     event_context;        /*   272     8 */
        struct dm_md_mempools *    mempools;             /*   280     8 */
        struct list_head           target_callbacks;     /*   288    16 */

        /* size: 304, cachelines: 5, members: 19 */
        /* sum members: 300 */
        /* sum bitfield members: 3 bits, bit holes: 1, sum bit holes: 29 bits */
        /* last cacheline: 48 bytes */
};

struct dm_ioctl {
        __u32                      version[3];           /*     0    12 */
        __u32                      data_size;            /*    12     4 */
        __u32                      data_start;           /*    16     4 */
        __u32                      target_count;         /*    20     4 */
        __s32                      open_count;           /*    24     4 */
        __u32                      flags;                /*    28     4 */
        __u32                      event_nr;             /*    32     4 */
        __u32                      padding;              /*    36     4 */
        __u64                      dev;                  /*    40     8 */
        char                       name[128];            /*    48   128 */
        /* --- cacheline 2 boundary (128 bytes) was 48 bytes ago --- */
        char                       uuid[129];            /*   176   129 */
        /* --- cacheline 4 boundary (256 bytes) was 49 bytes ago --- */
        char                       data[7];              /*   305     7 */

        /* size: 312, cachelines: 5, members: 12 */
        /* last cacheline: 56 bytes */
};

struct dm_target_spec {
        __u64                      sector_start;         /*     0     8 */
        __u64                      length;               /*     8     8 */
        __s32                      status;               /*    16     4 */
        __u32                      next;                 /*    20     4 */
        char                       target_type[16];      /*    24    16 */

        /* size: 40, cachelines: 1, members: 5 */
        /* last cacheline: 40 bytes */
};

参数：
dm_ioctl + dm_target_spec + string

1、创建dm设备
dmsetup create test --table "0 20971520 linear /dev/sda 0"

[2023-01-29 17:32:32]  open("/dev/mapper/control", O_RDWR)     = 3
[2023-01-29 17:32:32]  ioctl(3, DM_VERSION, {version=4.0.0, data_size=16384, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=16384, flags=DM_EXISTS_FLAG}) = 0
[2023-01-29 17:32:32]  ioctl(3, DM_DEV_CREATE, {version=4.0.0, data_size=16384, name="test", flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG}) = 0
[2023-01-29 17:32:32]  ioctl(3, DM_TABLE_LOAD, {version=4.0.0, data_size=16384, data_start=312, name="test", target_count=1, flags=DM_EXISTS_FLAG, {sector_start=0, length=20971520, target_type="linear", string="/dev/sda 0"}} => {version=4.43.0, data_size=305, data_start=312, dev=makedev(252, 0), name="test", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_INACTIVE_PRESENT_FLAG}) = 0
[2023-01-29 17:32:32]  ioctl(3, DM_DEV_SUSPEND, {version=4.0.0, data_size=16384, name="test", event_nr=6321407, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test", target_count=1, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_UEVENT_GENERATED_FLAG}) = 0

// 创建dm设备 dm-0 模块初始化时固定主设备号，次设备号带有DM_PERSISTENT_DEV_FLAG标记时可设置，或自动获取
ioctl // DM_DEV_CREATE
 vfs_ioctl
  dm_ctl_ioctl // filp->f_op->unlocked_ioctl
   ctl_ioctl
	lookup_ioctl
	dev_create
	 dm_create // 创建mapped_device md
	  dm_sysfs_init
	   kobject_init_and_add
		kobject_init // kobj->ktype = dm_ktype // dm_ktype.sysfs_ops	= &dm_sysfs_ops
		kobject_add_varg
	 dm_hash_insert
	  alloc_cell // 创建hash_cell hc->md = md
	  list_add // cell->name_list, _name_buckets
	  list_add // cell->uuid_list, _uuid_buckets
	 __dev_status // 回填dm_ioctl


// 构建映射表 -- target设备为/dev/sda
ioctl // DM_TABLE_LOAD 传入参数为 dm_ioctl + param->target_count * (dm_target_spec + string)
table_load
 find_device // 通过para中带的name或者uuid从_name_buckets/_uuid_buckets链表上取出创建的md设备
 dm_table_create // 创建dm_table
  alloc_targets // 分配targets  | t->highs --> [(num + 1) * sector] + [(num + 1) * dm_target] <-- t->targets |
 populate_table
  next_target // 从 dm_ioctl 尾后的 dm_target_spec 区域查找目标设备
   invalid_str // 校验target设备字符串
  dm_table_add_target // 将目标设备关联在dm_table的一项dm_target中
   dm_split_args // 解析target设备参数
   linear_ctr // 构建linear设备映射，关联linear设备与target设备
  dm_table_complete
   dm_table_determine_type // 根据t->targets设置t->type
   dm_table_build_index // 构建B树组织目标设备的存储空间 sector
   dm_table_register_integrity // 完整性校验
   dm_table_alloc_md_mempools
    dm_table_get_type // t->type
    dm_alloc_md_mempools // t->mempools type为DM_TYPE_REQUEST_BASED分配pools->bs，其他合法类型分配pools->io_bs
 // 检查immutable_target_type
 // 检查设置md->type
 // hc->new_map = t
 __dev_status // 更新para中的状态

// resume
ioctl // DM_DEV_SUSPEND
dev_suspend
 do_resume
  dm_swap_table
   __bind // 设置md->map为new_map，返回old_map
    dm_table_set_restrictions
  dm_resume
   __dm_resume
    dm_table_resume_targets

2、挂起dm设备
dmsetup suspend test
[2023-01-29 19:04:27]  open("/dev/mapper/control", O_RDWR)     = 3
[2023-01-29 19:04:27]  ioctl(3, DM_VERSION, {version=4.0.0, data_size=16384, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=16384, flags=DM_EXISTS_FLAG}) = 0
[2023-01-29 19:04:27]  ioctl(3, DM_DEV_SUSPEND, {version=4.0.0, data_size=16384, name="test", flags=DM_SUSPEND_FLAG|DM_EXISTS_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test", target_count=1, open_count=0, event_nr=0, flags=DM_SUSPEND_FLAG|DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG}) = 0

// suspend
ioctl // DM_DEV_SUSPEND
dev_suspend
 do_suspend
  dm_suspend
  __dev_status

3、reload设备
dmsetup reload test --table "0 2048 linear /dev/mapper/test 0"

[2023-01-29 20:39:40]  open("/dev/mapper/control", O_RDWR)     = 3
[2023-01-29 20:39:40]  ioctl(3, DM_VERSION, {version=4.0.0, data_size=16384, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=16384, flags=DM_EXISTS_FLAG}) = 0
[2023-01-29 20:39:40]  ioctl(3, DM_TABLE_LOAD, {version=4.0.0, data_size=16384, data_start=312, name="test", target_count=1, flags=DM_EXISTS_FLAG, {sector_start=0, length=2048, target_type="linear", string="/dev/mapper/test 0"}} => {version=4.43.0, data_size=305, data_start=312, dev=makedev(252, 0), name="test", target_count=1, open_count=1, event_nr=0, flags=DM_SUSPEND_FLAG|DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_INACTIVE_PRESENT_FLAG}) = 0

reload时target设备为/dev/mapper/test

4、resume设备
dmsetup resume test

// 异常resume
[2023-01-29 20:41:38]  open("/dev/mapper/control", O_RDWR)     = 3
[2023-01-29 20:41:38]  ioctl(3, DM_VERSION, {version=4.0.0, data_size=16384, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=16384, flags=DM_EXISTS_FLAG}) = 0

// 使用非自身的设备reload后正常resume
[2023-01-29 20:43:45]  open("/dev/mapper/control", O_RDWR)     = 3
[2023-01-29 20:43:45]  ioctl(3, DM_VERSION, {version=4.0.0, data_size=16384, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=16384, flags=DM_EXISTS_FLAG}) = 0
[2023-01-29 20:43:45]  ioctl(3, DM_DEV_SUSPEND, {version=4.0.0, data_size=16384, name="test", event_nr=6291566, flags=DM_EXISTS_FLAG} => {version=4.43.0, data_size=305, dev=makedev(252, 0), name="test", target_count=1, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_UEVENT_GENERATED_FLAG}) = 0

ioctl // DM_DEV_SUSPEND
dev_suspend
 do_resume
  dm_swap_table
   __bind // 设置md->map为new_map，返回old_map
    dm_table_set_restrictions
	 dm_table_supports_dax // dm_table *t, device_not_dax_capable
	  linear_iterate_devices // ti->type->iterate_devices; dm_target t->targets, device_not_dax_capable
	   device_not_dax_capable // dm_target t->targets, dm_dev (linear_c t->targets->private)->dev
	    dax_supported // dax_device (dm_dev dev)->dax_dev
		 dm_dax_supported // dax_dev->ops->dax_supported; dax_device dax_dev
		  dm_table_supports_dax // 此处调用使用的dm_table与第一次调用相同 陷入循环

t --> ti --> ti->private --> lc->dev --> dev->dax_dev --> dax_dev->ops->dax_supported

struct dm_table {
        struct dm_target *         targets;              /*   224     8 */ 与dm设备地址范围对应的target设备信息
};
struct dm_target {
        void *                     private;              /*    64     8 */
};
struct linear_c {
        struct dm_dev *            dev;                  /*     0     8 */
};
struct dm_dev {
        struct block_device *      bdev;                 /*     0     8 */
        struct dax_device *        dax_dev;              /*     8     8 */
        fmode_t                    mode;                 /*    16     4 */
        char                       name[16];             /*    20    16 */
};


关键流程：
// 调用create创建dax
dm_create
 alloc_dev
  alloc_dax // dax_dev初始化 ops: dm_dax_ops
   dax_add_host
    dax_host_hash
	hlist_add_head // dax_host_list
   // dax_dev->private = private; dax_dev的private指向 mapped_device *md

// reload
linear_ctr
 dm_get_device
  dm_get_dev_t // 获取dev_t
  find_device // 在(dm_table *)t->devices链表中查找dm_dev_internal
  dm_get_table_device // 分配初始化dm_dev_internal
   find_table_device // 在(mapped_device *)md->table_devices链表中查找table_device
   open_table_device // 分配初始化table_device
    dax_get_by_host // 获取dax_dev

// resume
dev_suspend
 do_resume
  // new_map = hc->new_map
  dm_swap_table
   __bind
    rcu_assign_pointer(md->map, (void *)t); // 第二次调用map来源于md->map，即hc->new_map
    dm_table_set_restrictions
	 dm_table_supports_dax // 第一次调用
	  linear_iterate_devices
	   device_not_dax_capable
	    dax_supported
		 dm_dax_supported
		  dm_table_supports_dax // 第二次调用 -- 此处调用使用的dm_table与第一次调用相同 陷入循环

两次调用设备类型相同 -- 钩子函数相同
两次调用map相同 -- 参数相同
(1)第一次调用dm_table_supports_dax
do_resume:
hc = __find_device_hash_cell(param);
hc->new_map
(2)第二次调用dm_table_supports_dax
dm_dax_supported:
md = dax_get_private(dax_dev) // 获取md
map = dm_get_live_table(md, &srcu_idx) // 获取map




修复方案：
在reload流程中检测目标设备是否为自身
......
linear_ctr
 dm_get_device
  dm_get_table_device
   open_table_device
    bd_link_disk_holder // 检测目标设备是否为自身 (bdev->bd_disk == disk)
......
```

# 三、dm-thin-pool

```c
dmsetup create linear_1 --table "0 2097152 linear /dev/sdc 0"
dmsetup create linear_2 --table "0 16777216  linear /dev/sdc 2097153"
dd if=/dev/zero of=/dev/mapper/linear_1 bs=4096 count=1
dmsetup create pool --table "0 16777216 thin-pool /dev/mapper/linear_1 /dev/mapper/linear_2 1024 0 1 skip_block_zeroing"
/*
块管理
struct dm_block_manager { // 关键成员是 dm_bufio_client
	struct dm_bufio_client *bufio;
	bool read_only:1;
};
struct dm_bufio_client { // 关键成员是变长的 dm_buffer_cache
...
	struct dm_buffer_cache cache; // must be last member
};
struct dm_buffer_cache { // 用于管理(元)数据块，CLEAN/DIRTY 两个链表，管理(元)数据块的 tree
	struct lru lru[LIST_SIZE];
	unsigned int num_locks;
	struct buffer_tree trees[];
};
// 根节点
struct buffer_tree {
	struct rw_semaphore lock;
	struct rb_root root;
}
*/
    
/*
pool_create
 dm_pool_metadata_open // 分配初始化 dm_pool_metadata *pmd
  __create_persistent_data_objects
   dm_block_manager_create // 分配 dm_block_manager pmd->bm
    dm_bufio_client_create // 分配 dm_bufio_client bm->bufio(dm_bufio_client_create + num_locks*buffer_tree)
                           // num_locks*buffer_tree 对应于 c->cache 的空间
                           // tree 用于管理 dm_buffer，与 block 对应
     cache_init // 初始化 dm_buffer_cache c->cache
      lru_init // 初始化 LIST_CLEAN/LIST_DIRTY 两个lru链表
     dm_io_client_create // 分配 dm_io_client c->dm_io
     register_shrinker // 初始化并注册shrinker，用于内存管理
   __open_or_format_metadata
    __superblock_all_zeroes // 读取 THIN_SUPERBLOCK_LOCATION 块数据，确认是否全0
    __format_metadata // 如果全0，并且需要format，则format
     dm_tm_create_with_sm
      dm_tm_create_internal
       dm_sm_metadata_init // 分配包含 dm_space_map 的 sm_metadata，并返回 dm_space_map 赋值给 pmd->metadata_sm -- &ops
       dm_tm_create // 分配初始化 dm_transaction_manager pmd->tm ---- pmd->tm->sm=pmd->metadata_sm;pmd->tm->bm=pmd->bm
       dm_sm_metadata_create // 初始化 sm_metadata
       dm_sm_disk_create // 分配 包含 dm_space_map 的 sm_disk，并返回 dm_space_map 赋值给 pmd->metadata_sm
       dm_tm_create_non_blocking_clone // 分配初始化 dm_transaction_manager pmd->nb_tm，包含 pmd->tm
       __setup_btree_details // 初始化各个 dm_btree_info
       dm_btree_empty // 创建 root
    __open_metadata // 否则，直接打开
     dm_block_data // 读取磁盘上的超级块信息 thin_disk_superblock
     dm_tm_open_with_sm // 初始化 pmd->metadata_sm (metadata_space_map_root)
      dm_tm_create_internal
       dm_sm_metadata_init // 创建sm
       dm_tm_create // 根据bm和sm创建tm
       dm_sm_metadata_open
        sm_ll_open_metadata // 加载metadata_space_map_root 使用disk_super->metadata_space_map_root初始化disk_sm_root再初始化ll_disk
        metadata_ll_open // 根据metadata_space_map_root中元数据bitmap所在块号，加载元数据space map信息
     dm_sm_disk_open // 初始化 pmd->data_sm (data_space_map_root)
      // 分配sm_disk，初始化 pmd->data_sm 为 static struct dm_space_map ops
      sm_ll_open_disk // 加载 data_space_map_root 使用disk_super->data_space_map_root 初始化disk_sm_root再初始化ll_disk
                      // 初始化时只保存根节点信息，数据块的 disk_index_entry 信息在初始化时没有加载，在查找空闲块时会加载
     pmd->root = disk_super->data_mapping_root // 保存数据映射信息根节点
	 pmd->details_root = disk_super->device_details_root // 保存设备信息根节点
  
*/
dmsetup message /dev/mapper/pool 0 "create_thin 0"
/*
pool_message
 process_create_thin_mesg
  dm_pool_create_thin
   __create_thin
    dm_btree_lookup // 当前 thin_id 是否已经存在 pmd->details_root 树中
    dm_btree_empty // 基于 pmd->bl_info 创建一个新的空树(新的 btree_node 根节点)
     new_block // 通过 pmd->metadata_sm 分配元数据块
    dm_btree_insert // 以 thin_id 为键，新创建thin设备对应的空树 btree_node 根节点为值，将键值对插入 pmd->tl_info 树中，并更新 pmd->root
    __open_device // 根据 thin_id 查找dm_thin_device，若没有则创建。 打开设备 (*td)->open_count = 1
    dm_btree_remove // 若打开失败，从 pmd->tl_info 上删除键值对
    dm_btree_del // 若打开失败，基于 pmd->bl_info 释放空树
    __close_device // 关闭设备 --td->open_count
 commit
  dm_pool_commit_metadata
   __commit_transaction
    __write_changed_details
     dm_btree_insert // 遍历 pmd->thin_devices 插入 pmd->details_info

*/
dmsetup create thin --table "0 14680064 thin /dev/mapper/pool 0"
/*
sector_start=0, length=14680064, target_type="thin"
pool_dev=“/dev/mapper/pool”
tc->dev_id=0
*/
    
[root@localhost ~]# lsblk
NAME        MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda           8:0    0   10G  0 disk
sdb           8:16   0    5G  0 disk
sdc           8:32   0   20G  0 disk
├─linear_1  252:0    0    1G  0 dm
│ └─pool    252:2    0    8G  0 dm
│   ├─thin  252:3    0  512M  0 dm
│   └─thin1 252:4    0  512M  0 dm
└─linear_2  252:1    0    8G  0 dm
  └─pool    252:2    0    8G  0 dm
    ├─thin  252:3    0  512M  0 dm
    └─thin1 252:4    0  512M  0 dm
sdd           8:48   0    8M  0 disk
vda         253:0    0   20G  0 disk /
[root@localhost ~]# dmsetup table
thin: 0 1048576 thin 252:2 0
linear_2: 0 16777216 linear 8:32 2097153
linear_1: 0 2097152 linear 8:32 0
thin1: 0 1048576 thin 252:2 1
pool: 0 16777216 thin-pool 252:0 252:1 1024 0 1 skip_block_zeroing
[root@localhost ~]#

// 初始化worker
pool_ctr
 __pool_find
  pool_create
   // INIT_WORK(&pool->worker, do_worker);

// 唤醒worker
wake_worker
 // queue_work(pool->wq, &pool->worker);

// worker执行流程
do_worker
 process_deferred_bios
  get_first_thin // 从pool->active_thins中获取thin，thin设备通过thin_ctr将自身添加到链表中
  process_thin_deferred_bios // 处理当前thin设备上的bio
   bio_list_merge // 将tc->deferred_bio_list存到局部变量，同时清空tc->deferred_bio_list
   process_bio // pool->process_bio 取出第一个bio进行处理
    get_bio_block // 根据bio的sector计算对应的block
	build_virtual_key // 使用目标 逻辑 block 初始化cell key
	bio_detain // drivers\md\dm-thin.c
	 dm_bio_prison_alloc_cell // 从prison的mempool里分配cell
	 dm_bio_detain
	  bio_detain // drivers\md\dm-bio-prison-v1.c
	   __bio_detain // 从prison->cells的红黑树里查找key对应的cell是否存在，如果存在，则使用已有的cell，否则使用新分配的cell
	    __setup_new_cell // 初始化新分配的cell，将cell与key/bio关联起来(cell->key/cell->holder)
	process_cell // 处理新分配的cell

process_cell
 dm_thin_find_block # 根据逻辑块号，找到对应的信息(物理块号及共享信息)
  __find_block // 两级索引，第一级索引key为thin id，第二级索引key为逻辑块号
   dm_btree_lookup
	// 第一次循环，查找目标thin的映射信息所在块；第二次循环，查找目标thin上逻辑块与物理块的对应关系
	btree_lookup_raw
	 ro_step // 将new_child对应的数据读取到s->nodes + s->count对应的buffer中 --> struct btree_node
	 ro_node // 获取s->nodes[s->count - 1]对应的buffer --> struct btree_node
	 lower_bound // search_fn 在btree_node中二分查找指定的key，返回对应的索引值
	 // 如果是中间结点，则根据刚刚获取到的索引值，查找下一级结点所在的block
	 // 返回查找到的key值rkey与value值value_p
   unpack_lookup_result
	// 从查找到的value值中解析出物理块号exception_block和时间exception_time(根据time判断是否共享)
 process_shared_bio # 如果该块是多个thin设备共享的块号，需要进行处理
  build_data_key // 使用目标 物理 block 初始化cell key
   break_sharing
     alloc_data_block # 返回 -EINVAL
       dm_pool_alloc_data_block # 返回 -EINVAL
         dm_sm_new_block // 新分配一个块，用于data
		 // pmd->data_sm
		 // container_of(sm, struct sm_disk, sm)
		 // smd->old_ll
           sm_disk_new_block # 通过 sm->new_block 回调
             sm_ll_find_free_block // 计算bitmap的逻辑块范围，根据逻辑块号依次读取存放bitmap信息的物理块，根据bitmap信息查找空闲块
			  // 遍历bitmap的所有块，每个块上包含ll->entries_per_block个entry，表示块的使用情况
			  disk_ll_load_ie // ll->load_ie 查找bitmap逻辑块i对应的 disk_index_entry ，存在ie_disk中
			  dm_tm_read_lock //  根据 disk_index_entry 判断，有空闲块，查找物理块ie_disk.blocknr，获取详细的bitmap信息
			  sm_find_free // 查找空闲块
			  // 计算空闲块并返回 *result = i * ll->entries_per_block + (dm_block_t) position;
             sm_ll_inc
               sm_ll_mutate(inc_ref_count)
                disk_ll_load_ie // ll->load_ie
                dm_tm_shadow_block # 此处返回错误 -EINVAL
                 dm_sm_count_is_more_than_one
                  sm_disk_count_is_more_than_one // sm->count_is_more_than_one 判断引用计数是否大于1
                   sm_disk_get_count // 获取引用计数
                	sm_ll_lookup
                	 sm_ll_lookup_bitmap // 引用计数小于3，则从bitmap中获取
                	 sm_ll_lookup_big_ref_count // 引用计数大于等于3，则从引用计数信息保存块上获取
                	  dm_btree_lookup
                 __shadow_block
                  dm_sm_new_block # 新分配一个块， 用于metadata
				  // smd->ll
				  // ll->tm
				  // tm->sm
				  // container_of(sm, struct sm_metadata, sm)
				  // smm->old_ll
				   sm_metadata_new_block
				    sm_metadata_new_block_
					 sm_ll_find_free_block
                  dm_sm_dec_block # 原始块的引用计数 -1
                   sm_metadata_dec_block # 通过 sm->dec_block 回调
                    sm_ll_dec
                     sm_ll_mutate(dec_ref_count)
                      dec_ref_count
                       DMERR_LIMIT("unable to decrement a reference count below 0");
                  dm_bm_read_lock # 从原始块中读取数据
                  dm_bm_write_lock_zero # 新块中的数据清零
                  memcpy # 将老数据复制到新块中
                DMERR("dm_tm_shadow_block() failed");
      metadata_operation_failed(dm_pool_alloc_data_block)
       DMERR_LIMIT("%s: metadata operation '%s' failed: error = %d")
        abort_transaction
         DMERR_LIMIT("%s: aborting current metadata transaction", dev_name);
        set_pool_mode(PM_READ_ONLY)
         notify_of_pool_mode_change(read-only)
          DMINFO("%s: switching pool to %s mode")
    DMERR_LIMIT("%s: alloc_data_block() failed: error = %d",)



table_load
 populate_table
  dm_table_add_target
   dm_get_target_type // 根据字符串获取 target_type 用于赋值给tgt->type
    get_target_type
	 __find_target_type
	  // 根据传入的name参数寻找已注册的 target_type，thin 对应 thin_target
	  // thin设备将关联 thin_target，可以使用.ctr函数 thin_ctr
   thin_ctr // tgt->type->ctr

修改元数据块时，需要获取一个新的空闲元数据块，作为待修改块的shadow1。
获取用于作为shadow1的空闲元数据块，理论上需要修改该块的bitmap，即该块bitmap信息所在的块，也需要一个shadow2，这样会陷入无限递归。

要防止无限递归，需要做到两点：
1、获取作为shadow2的空闲块时，不会立刻修改shadow2块的bitmap，使得shadow2块在不修改bitmap的情况下可以使用
2、shadow2块在不修改bitmap的情况下使用时，要防止其他进程作为空闲块获取使用

解决方案：
1、获取shadow2块后不修改shadow2的bitmap，将修改请求加入等待队列
2、获取到一个空闲块后增加smm->begin，保证后一次查找空闲块的范围不包括前一次查找到的空闲块

dm_pool_alloc_data_block // 分配新的数据块
 dm_sm_new_block
  sm_disk_new_block
   sm_ll_find_free_block // 查找到空闲数据块
   sm_ll_inc // 修改空闲数据块的bitmap信息，增加空闲数据块的引用计数
    sm_ll_mutate
     dm_tm_shadow_block // 为保留新分配的空闲块bitmap信息的物理block分配shadow1
      __shadow_block
       dm_sm_new_block
        sm_metadata_new_block
         sm_metadata_new_block_
          sm_ll_find_free_block // 查找空闲元数据块shadow1
          smm->begin = *b + 1; // 更新begin，保证下一次调用该函数查找空闲块时，及时上次查找到的已经在使用的空闲块没有更新bitmap，也不会第二次被获取使用
          in(smm)
          sm_ll_inc // 修改空闲元数据块的bitmap信息，增加空闲元数据块的引用计数 shadow1
           sm_ll_mutate
            dm_tm_shadow_block
             __shadow_block
              dm_sm_new_block
               sm_metadata_new_block
                sm_metadata_new_block_
                 sm_ll_find_free_block // 查找空闲元数据块shadow2(由于begin已更新，shadow1和shadow2不会是同一个块)
                  recursing // 判断出当前正在shadow1的sm_ll_inc流程中
                  add_bop // 将对shadow2的sm_ll_inc操作将入等待队列
                  // 此时shadow2可供使用(bitmap更新操作延迟)，shadow1的sm_ll_inc流程可继续进行
          out(mm) // shadow1的sm_ll_inc流程完成后，会将等待队列中的请求取出执行

// 每次更新一个shadow的bitmap，一直更新到superblock中的metadata_space_map_root
```

## 关键函数

```c
int dm_btree_lookup(struct dm_btree_info *info, dm_block_t root,
		    uint64_t *keys, void *value_le);
/*
在指定btree上搜索指定key值对应的value值
info：提供搜索所需信息，如目标value的size等
root：待搜索btree的根节点所在块
keys：待搜索的key值
value_le：搜索结果

从btree的根节点所在块搜索btree_node->keys[]，如果btree_node->keys[x] == keys[0]，则x为values的索引，values[x]为下一级索引块的块号，或者是搜索结果
*/

int dm_btree_empty(struct dm_btree_info *info, dm_block_t *root);
/*
创建一个空的树
root：创建出的btree根节点所在块
*/

int dm_btree_lookup_next(struct dm_btree_info *info, dm_block_t root,
			 uint64_t *keys, uint64_t *rkey, void *value_le);
/*
查找下一个非空(已map)的块
root：待搜索btree的根节点所在块
keys：待搜索的key值
rkey：返回实际要搜索的key值
value_le：实际要搜索key值对应的value
*/

/* 不同的 info 用于不同的查找
例如同样是从超级块根节点pmd->root开始查找
__create_snap
	key = origin
	dm_btree_lookup(&pmd->tl_info, pmd->root, &key, &value);
	使用tl_info做一层查找，根据thin_id查找到thin映射所在块
	
__find_block
	keys[2] = { td->id, block };
	info = &pmd->info;
	dm_btree_lookup(info, pmd->root, keys, &value)
	使用info做两层查找，先根据thin_id查找到thin映射所在块，再根据block查找映射关系
*/

static int btree_insert_raw(struct shadow_spine *s, dm_block_t root,
			    struct dm_btree_value_type *vt,
			    uint64_t key, unsigned *index)
/*
从root开始，查找到leaf节点，查找目标key在leaf节点上的位置(已经在树中的返回当前位置，不在树中的返回待插入位置)
*/

static void insert_shadow(struct dm_transaction_manager *tm, dm_block_t b)
/*
将某个block标志成shadow
*/
static void __setup_btree_details(struct dm_pool_metadata *pmd)
{
    /* 2层的B树，第一层为thin设备，第二层为thin的映射 */
	pmd->info.tm = pmd->tm;
	pmd->info.levels = 2;
	pmd->info.value_type.context = pmd->data_sm;
	pmd->info.value_type.size = sizeof(__le64);
	pmd->info.value_type.inc = data_block_inc;
	pmd->info.value_type.dec = data_block_dec;
	pmd->info.value_type.equal = data_block_equal;

	memcpy(&pmd->nb_info, &pmd->info, sizeof(pmd->nb_info));
	pmd->nb_info.tm = pmd->nb_tm;

	pmd->tl_info.tm = pmd->tm;
	pmd->tl_info.levels = 1;
	pmd->tl_info.value_type.context = &pmd->bl_info;
	pmd->tl_info.value_type.size = sizeof(__le64);
	pmd->tl_info.value_type.inc = subtree_inc;
	pmd->tl_info.value_type.dec = subtree_dec;
	pmd->tl_info.value_type.equal = subtree_equal;

	pmd->bl_info.tm = pmd->tm;
	pmd->bl_info.levels = 1;
	pmd->bl_info.value_type.context = pmd->data_sm;
	pmd->bl_info.value_type.size = sizeof(__le64);
	pmd->bl_info.value_type.inc = data_block_inc;
	pmd->bl_info.value_type.dec = data_block_dec;
	pmd->bl_info.value_type.equal = data_block_equal;

    // 用于操作 device_details_root
    /* 描述thin设备详细信息的B树 */
	pmd->details_info.tm = pmd->tm;
	pmd->details_info.levels = 1;
	pmd->details_info.value_type.context = NULL;
	pmd->details_info.value_type.size = sizeof(struct disk_device_details);
	pmd->details_info.value_type.inc = NULL;
	pmd->details_info.value_type.dec = NULL;
	pmd->details_info.value_type.equal = NULL;
}

static int thin_map(struct dm_target *ti, struct bio *bio)
thin_map
 thin_bio_map
  dm_thin_find_block // 查找逻辑块对应的物理块
  1) 找到
   将bio调整为指向数据盘实际物理块的bio，返回 DM_MAPIO_REMAPPED 由上层再次下发
  2) 没找到
   延迟由worker处理，返回 DM_MAPIO_SUBMITTED
   thin_defer_cell // -ENODATA/-EWOULDBLOCK




```
# 四、dm-multipath

```C
dm_read_arg：读取当前索引到的参数字符串
dm_read_arg_group：读取当前索引到的参数字符串，当前字符串为一组参数的个数，总参数的个数要大于一组参数的个数
dm_shift_arg：返回当前参数，指向下一个参数

dmsetup reload test --table "0 20971520 multipath 1 queue_if_no_path 0 1 1 service-time 0 2 1 8:0 1 8:16 1"
1：一个可选特性
queue_if_no_path：可选特性
0：hw_argc
1：nr_priority_groups
1：next_pg_num
service-time：path_selector_type
0：ps_argc 创建path_selector参数个数
1：nr_pgpaths 路径数，即设备数
1：nr_selector_args 初始化selector参数个数
8:0：设备号
1：path_selector_type add_path回调参数 service-time是repeat_count

strace -v multipath -v2
strace -v -s 512 multipath -v2

// 查看目标设备是否存在
ioctl(3, DM_DEV_STATUS, {version=4.0.0, data_size=16384, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG} => {version=4.39.0, data_size=16384, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG}) = -1 ENXIO (No such device or address)

// 创建设备
ioctl(3, DM_DEV_CREATE, {version=4.0.0, data_size=16384, name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG} => {version=4.39.0, data_size=305, dev=makedev(252, 0), name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG}) = 0

// load设备
ioctl(3, DM_TABLE_LOAD, {version=4.0.0, data_size=16384, data_start=312, dev=makedev(252, 0), target_count=1, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG, {sector_start=0, length=209715200, target_type="multipath", string="1 queue_if_no_path 0 1 1 service-time 0 1 1 8:0 1"}} => {version=4.43.0, data_size=305, data_start=312, dev=makedev(252, 0), name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", target_count=0, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_PERSISTENT_DEV_FLAG|DM_INACTIVE_PRESENT_FLAG}) = 0

// resume设备
ioctl(3, DM_DEV_SUSPEND, {version=4.0.0, data_size=16384, name="0QEMU_QEMU_HARDDISK_ds_1", event_nr=4247926, flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG} => {version=4.39.0, data_size=305, dev=makedev(252, 0), name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", target_count=1, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_SKIP_BDGET_FLAG|DM_UEVENT_GENERATED_FLAG}) = 0

// send message -- switch_group
ioctl(3, DM_TARGET_MSG, {version=4.2.0, data_size=16384, data_start=312, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG, {sector=0, message="switch_group 1"}} => {version=4.39.0, data_size=305, data_start=312, dev=makedev(252, 0), name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", target_count=1, open_count=0, event_nr=0, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_SKIP_BDGET_FLAG}) = 0

// 设置参数 -- 关联实际设备
ioctl(3, DM_DEV_SET_GEOMETRY, {version=4.6.0, data_size=16384, data_start=312, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG, string="13054 255 63 0"} => {version=4.39.0, data_size=16384, data_start=312, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG}) = 0

/*
 * open("/dev/sda", O_RDONLY)              = 4
 * ioctl(4, HDIO_GETGEO, {heads=255, sectors=63, cylinders=13054, start=0}) = 0
 */

// send message -- fail_if_no_path
ioctl(3, DM_TARGET_MSG, {version=4.2.0, data_size=16384, data_start=312, name="0QEMU_QEMU_HARDDISK_ds_1", flags=DM_EXISTS_FLAG|DM_SKIP_BDGET_FLAG, {sector=0, message="fail_if_no_path"}} => {version=4.39.0, data_size=305, data_start=312, dev=makedev(252, 0), name="0QEMU_QEMU_HARDDISK_ds_1", uuid="mpath-0QEMU_QEMU_HARDDISK_ds_1", target_count=1, open_count=0, event_nr=1, flags=DM_EXISTS_FLAG|DM_ACTIVE_PRESENT_FLAG|DM_SKIP_BDGET_FLAG}) = 0

[root@localhost ~]# lsblk
NAME                       MAJ:MIN RM  SIZE RO TYPE  MOUNTPOINT
sda                          8:0    0  100G  0 disk
└─0QEMU_QEMU_HARDDISK_ds_1 252:0    0  100G  0 mpath
sdb                          8:16   0   20G  0 disk
└─0QEMU_QEMU_HARDDISK_ds_2 252:1    0   20G  0 mpath
sdc                          8:32   0    8M  0 disk
vda                        253:0    0   20G  0 disk  /
[root@localhost ~]#
```

# 五、dm-snap
![dmsnapshot设备映射](https://github.com/user-attachments/assets/ca15e457-5ab8-4393-9508-5d226aba34e2)
![dmsnapshot_device_mapping](https://github.com/user-attachments/assets/73feded1-90cf-4d47-89c9-c2a23af4ad2f)
## **1、snapshot-origin**

Origin: maps a linear range of a device, with hooks for snapshotting.
Construct an origin mapping: <dev_path>

创建原始设备的线性映射
与 linear 设备相比，写 snapshot-origin 不影响原始设备

基于block device(origin)创建snapshot-origin
基于snapshot-origin创建多个snapshot

**读IO**：直接下发到 base 设备
**写IO**：首先将IO对应的数据区域，从 base 设备备份到 COW 设备，然后直接将写IO下发到 base 设备

#### 创建 snapshot-origin

```
// base reload 成 snapshot-origin，reload 到 base-real
DM_TABLE_LOAD 252:0 -- 252:1
```

### （1）origin_ctr

```
origin_ctr // ti:待初始化的 dm_target; argc:设备个数，只能为1; argv:目标设备
 dm_get_device
  __dm_get_device // o->dev.bdev 指向目标设备 block_device
  // dm_origin->ti = dm_target
  / /dm_target->private = dm_origin
```

### （2）origin_resume

```
origin_resume
 get_origin_minimum_chunksize // 获取 chunk_size，如果目标设备没有 snap，则为最大值，否则根据 snap 计算
 __insert_dm_origin // 将 dm_origin 插入 _dm_origins[] 某个链表中
```

### （3）origin_postsuspend

```
origin_postsuspend
 __remove_dm_origin // 将 dm_origin 从链表中删除
```

### （4）origin_status

```
origin_status // 打印目标设备的主次设备号
```

### （5）origin_map

#### a. 构造 job 异步执行

```c
origin_map // 读IO直接下发到原始设备，写IO下发到原始设备，同时将原始数据保留在COW设备中
dm_submit_bio
 __split_and_process_bio
  init_clone_info
   alloc_io // clone_info->io->orig_bio=bio; clone_info->map=dm_table
  __split_and_process_non_flush // clone_info->bio=bio
   dm_table_find_target // 查找到对应的 target
   __clone_and_map_data_bio
    alloc_tio // dm_target_io->io=clone_info->io; dm_target_io->ti=target
	clone_bio // 初始化 dm_target_io->clone -- 拷贝原始 bio
    __map_bio // 初始化 dm_target_io->clone -- 设置 clone bio 特有成员
	 origin_map // REQ_PREFLUSH 或者 非写IO，直接下发到原始设备
	  bio_set_dev // 设置目标设备
	  do_origin
	   __lookup_origin // 确认当前 snapshot-origin 的原始设备是否已经注册 snapshot
	   // 1) 原始设备没有 snapshot，返回 DM_MAPIO_REMAPPED，直接下IO
	   // 2) 原始设备有 snapshot
	   //   2.1) 限制
	             遍历当前 origin 所有的 snapshot
				 wait_for_in_progress // 若当前 snapshot 的拷贝操作数量超出限制，则调度出去等待唤醒
	   //   2.2) 无限制
	   __origin_write // 将待修改的原始设备区域内容保存到COW设备中
	                  // 如果不需要 exceptions，则返回 DM_MAPIO_REMAPPED，由调用者直接向原始设备下IO
					  // 如果需要 exceptions，则返回 DM_MAPIO_SUBMITTED，IO加入链表等待 exceptions 就绪
	    sector_to_chunk // 根据当前 snapshot 的 chunk_size 计算给定 sector 对应的 chunk 偏移
		dm_exception_table_lock_init // 初始化针对特定 snap 特定 chunk 的锁。 根据 chunk 偏移计算两个 dm_exception_table 中的索引，并保存在 dm_exception_table_lock 中
		down_read // 对 snapshot 加锁
		dm_exception_table_lock // 对特定 chunk 加锁
		__lookup_pending_exception // 在特定 snapshot 上根据特定 chunk 查找特定 dm_exception 及对应 dm_snap_pending_exception
		 dm_lookup_exception // 在 dm_snapshot->pending 链表上查
		dm_lookup_exception // 在 dm_snapshot->complete 链表上查
		alloc_pending_exception // 分配 dm_snap_pending_exception
		// 再次查找 pending complete 两个链表，如果没有，则将新的 dm_snap_pending_exception 插入
		__insert_pending_exception // pe->e.old_chunk = chunk
		 persistent_prepare_exception // s->store->type->prepare_exception e->new_chunk = ps->next_free
		 dm_insert_exception // 将 dm_exception 按 old_chunk 顺序插入 hlist_bl_head 链表
		bio_list_add // 将 bio 加入链表
		dm_exception_table_unlock // 对特定 chunk 解锁
		up_read // 对 snapshot 解锁
<--------------------------------- 将待修改的原始设备区域内容保存到COW设备中 --------------------------------->
		start_copy // 将待修改的原始设备区域内容保存到COW设备中 src(base old_chunk) --> dest(cow new_chunk)
		 account_start_copy // dm_snapshot->in_progress++ 当前 snapshot 正在处理的IO数增加
		 dm_kcopyd_copy // dm_snapshot->kcopyd_client --------> job->rw = READ
		  mempool_alloc // 从 dm_kcopyd_client->job_pool 中分配 kcopyd_job
		  dispatch_job // 若IO大小没有超过 dm_kcopyd_client->sub_job_size 限制，则直接下发IO
		   push // kcopyd_job 加入 dm_kcopyd_client->pages_jobs
		   wake // dm_kcopyd_client
		    queue_work // dm_kcopyd_client->kcopyd_work 加入 dm_kcopyd_client->kcopyd_wq
		     // dm_kcopyd_client->kcopyd_work
             // 先构造读请求 dm_io_request，从 base 设备中读数据，在回调函数 complete_io 中再构造写请求，写入 COW 设备
		  split_job // 若IO大小超过 dm_kcopyd_client->sub_job_size 限制，则拆分下发IO
```

#### b. 从原始设备读待修改区域的数据

```c
<--------------------------------- 1、从原始设备读待修改区域的数据 --------------------------------->
do_work
 // 第一次调用 do_work 不会下发原始IO到原始设备，此时 complete_jobs 为空，在备份完成，在 complete_io 中添加 job 到 complete_jobs
 process_jobs // 处理 pages_jobs
  run_pages_job
   kcopyd_get_pages
   push // kcopyd_job 加入 dm_kcopyd_client->io_jobs
 process_jobs // 处理 io_jobs
  run_io_job
   io_job_start // 根据 dm_kcopyd_client->throttle 限速
   dm_io
    async_io
	 dispatch_io
	  do_region // 从 base 设备中读数据
      dec_count
	   complete_io // drivers\md\dm-io.c
	    complete_io //io->callback --> fn --> io_req->notify.fn --> complete_io (drivers\md\dm-kcopyd.c)
         // 修改 --------> job->rw = WRITE
		 push // 写 job push 到 io_jobs
```



#### c. 将读取的数据备份到COW设备

```c
<--------------------------------- 2、将读取的数据备份到COW设备 --------------------------------->
do_work
 process_jobs // 处理 io_jobs
  run_io_job
   dm_io
    async_io
	 dispatch_io
	  do_region
      dec_count
	   complete_io // drivers\md\dm-io.c
	    complete_io //io->callback --> fn --> io_req->notify.fn --> complete_io (drivers\md\dm-kcopyd.c)
		 push // job push 到 complete_jobs
```



#### d. 下发原始IO到原始设备

```c
<--------------------------------- 3、下发原始IO到原始设备 --------------------------------->
do_work
 process_jobs // 处理 complete_jobs
  run_complete_job
   copy_callback
    complete_exception
     persistent_commit_exception // s->store->type->commit_exception
	  write_exception // 更新内存中的 chunk 映射信息
	  area_io // 映射信息落盘
      pending_complete // cb->callback
       dm_insert_exception // 插入 dm_snapshot->complete 链表
       dm_remove_exception // 从 dm_snapshot->pending 链表中删除
	   retry_origin_bios
	    do_origin
	     __origin_write // 在 dm_snapshot->complete 中，不在 dm_snapshot->pending 中，返回 DM_MAPIO_REMAPPED
	    submit_bio_noacct // 下发原始IO到原始设备
```



## **2、snapshot**

对snapshot所有的写操作，数据都会落盘到\<COW device\>；读操作，数据可能来源于\<COW device\>，也可能来源于origin。

**persistent/transient**
persistent：数据落盘
transient：只有较少的元数据保存在磁盘上，其他元数据保存在内存中。

**Overflow**
可以在 snapshot 状态中查看到溢出情况

**Optional features**
discard_zeroes_cow：下发 discard 命令会清空对应cow区域数据
discard_passdown_origin：下发 discard 命令会下发至原始设备

**snapshot**涉及的4种设备：
1、原始设备
2、cow设备
3、基于原始设备与cow设备创建的snapshot设备
4、snapshot-origin设备（可选？单独存在，snapshot基于snapshot-origin或者直接基于原始设备？）

**读IO**：根据映射是否存在决定下发至原始设备还是 COW 设备（映射存在，说明曾经写过这块数据，新数据保存在 COW 设备中，应将 IO 下发至 COW 设备，否则下发至原始设备）

**写IO**：下发至 COW 设备，映射不存在则创建映射

### 创建 snapshot

```
// 创建 snapshot 设备 snap，load 到 base-real 和 snap-cow
// 指定 chunksize(sector个数)
DM_DEV_CREATE name="volumeGroup-snap"
DM_TABLE_LOAD 252:3 -- 252:1 252:2 P 8
```



### （1）snapshot_ctr

```c
snapshot_ctr // 初始化 dm_target， 分配初始化 dm_snapshot，保存在 dm_target->private 中
 dm_consume_args // 跳过前面4个特殊参数，处理后面跟的 feature 参数
 parse_snapshot_features // 处理 feature 参数，保存在 dm_snapshot 中
 dm_get_device // get 原始设备，保存在 dm_snapshot->origin
 dm_get_device // get cow设备，保存在 dm_snapshot->cow
 dm_exception_store_create // 分配初始化 dm_exception_store 保存在 dm_snapshot->store
  get_type // 获取 dm_exception_store_type -- "P" 保存在 dm_exception_store->type
  set_chunk_size // 设置 chunk_size 为 8  dm_exception_store->chunk_size
   dm_exception_store_set_chunk_size
  persistent_ctr // 分配初始化 pstore，保存在 dm_exception_store->context
 init_hash_tables // 初始化 dm_snapshot->pending dm_snapshot->complete
 dm_kcopyd_client_create // 初始化 dm_snapshot->kcopyd_client
 mempool_init_slab_pool // 初始化 dm_snapshot->pending_pool
 register_snapshot
  __validate_exception_handover // 确认同 origin/cow 设备的 dm_snapshot 情况，第一个新 dm_snapshot 返回0
   __find_snapshots_sharing_cow // 使用当前 cow 设备注册的 dm_snapshot 个数
  __lookup_origin // 根据 dm_snapshot->origin 查找 struct origin
  __insert_origin // 将新的 struct origin 根据 dm_snapshot->origin->bdev 插入 _origin[] 对应的链表中
  __insert_snapshot // 将当前 dm_snapshot 插入新的 struct origin 的 snapshot 链表中
 persistent_read_metadata // s->store->type->read_metadata
  read_header // 从 cow 设备读取 disk_header
   chunk_io // 构造 snapshot 读写请求，下发到 cow 设备
    dm_io
	 sync_io
  // 1) cow 上没有 header，即第一次创建快照
  write_header // 将 disk_header 写入 cow 设备
   chunk_io
    dm_io
	 sync_io
  zero_memory_area // 清空 pstore->area
  zero_disk_area // pstore->zero_area 落盘到第二个 chunk 位置，清 chunk ？
  // 2) cow 上已有 header，非第一次创建快照
  read_exceptions // 从 cow 上读取 exception 信息到内存
   // 遍历已有映射数据保存的 area
   insert_exceptions // 遍历当前 area 上所有的 exception
    read_exception // 读取 exception
    dm_add_exception // 将读取到的 exception 插入 s->complete 链表
 dm_set_target_max_io_len
```

### （2）snapshot_preresume

```
// 根据使用相同 cow 设备的 snapshot 及 target 状态，判断是否可以resume
snapshot_preresume
 __find_snapshots_sharing_cow // 查找使用相同 cow 设备的 snapshot
```



### （3）snapshot_resume

```
snapshot_resume
 __lookup_dm_origin // 当前 snapshot 的 base 设备是否有 snapshot-origin 已经 resume
 // 如果 snapshot 的 dm_table 与 dm snapshot-origin 的不同，则 dm_hold 增加引用计数
 __find_snapshots_sharing_cow
 dm_internal_resume_fast
 reregister_snapshot // 根据当前的 chunksize 重新插入 _origin 链表
```



### （4）snapshot_status

```
snapshot_status
 STATUSTYPE_INFO
 // 当前状态
 STATUSTYPE_TABLE
 // dm_exception_store 信息与相关 feature
```



### （5）snapshot_map

```c
snapshot_map
 init_tracked_chunk // 根据 bio 获取 dm_snap_tracked_chunk (两者在内存上连续，由 bio 地址减偏移得到)
 // 如果是 REQ_PREFLUSH 请求，则直接下发至 cow 设备
 sector_to_chunk // 将 bio 中的起始 sector 转换成 chunk
 dm_exception_table_lock_init // 获取特定 snapshot 特定 chunk 的锁
 wait_for_in_progress // 如果是写IO，则在当前 snapshot 的拷贝操作数量超出限制时，调度出去等待唤醒
 dm_exception_table_lock // 对特定 snapshot 特定 chunk 加锁
 // 如果是 REQ_OP_DISCARD 请求，在使能 discard_passdown_origin 时将请求下发至原始设备，并且 track 当前 chunk
 dm_lookup_exception // 查找特定 snapshot 特定 chunk 是否已经映射 (原始设备的 old_chunk 映射到 COW 设备的 new_chunk)
<--------------------------------- 映射已存在 --------------------------------->
 remap_exception // 如果已经映射，则根据映射关系将 bio 修改成针对 COW 设备的 bio (修改目标设备与 sector 偏移)
  // 如果是 REQ_OP_DISCARD 请求，并且 discard 的刚好是一整个 chunk (io_overlaps_chunk)，则直接清空 COW 设备上的对应 chunk
  // 否则，返回上层继续下发已经修改过的 bio
<--------------------------------- 映射不存在 --------------------------------->
 // 如果是 REQ_OP_DISCARD 请求，并且没有映射，则直接结束 IO
 // 如果是读IO，则直接修改目标设备为原始设备，track chunk 后返回上层下发IO
 // 如果是写IO
 1、pending 链表里没有该 chunk
    分配 pending exception
	1.1 如果 complete 链表里有包含该 chunk 的 exception
	    说明映射关系存在，之前已经完成过相关IO
		free_pending_exception // 释放刚刚分配的 pending exception
		remap_exception // 据映射关系将 bio 修改成针对 COW 设备的 bio，返回上层继续下发已经修改过的 bio
	1.2 如果 complete 链表里没有包含该 chunk 的 exception
	    使用该 chunk 初始化 pending exception 并插入 pending 链表，插入失败则直接返回错误给上层
 2、pending 链表里有该 chunk
    remap_exception
	io_overlaps_chunk // 操作范围刚好是一整个 chunk
	// 使用 start_full_bio 启动IO
	bio_list_add // 将 bio 插入 snapshot_bios 链表中
	start_copy // 启动IO
```

### （6）snapshot_end_io

```
snapshot_end_io
 is_bio_tracked // 判断特定 snapshot 的特定 chunk 是否被 track
 stop_tracking_chunk // 如果被 track，则从链表中删除，停止 track
```



## **3、snapshot-merge**

接管已有snapshot在\<COW device\>中修改数据，开始merge后snapshot将无法访问

snapshot-merge 本质上也是一个 snapshot，其 dm_target->private 为 dm_snapshot

### （1）snapshot_merge_presuspend

```c
snapshot_merge_presuspend
 stop_merge // 设置 SHUTDOWN_MERGE，停止 merge 流程，等待 RUNNING_MERGE 置位后唤醒
```

### （2）snapshot_merge_resume

```c
snapshot_merge_resume
 snapshot_resume // snapshot-merge 与 snapshot 的原始设备和 COW 设备是一样的，通过 mapped_device 来确认当前 dm_target 是 snapshot-merge 还是 snapshot
 start_merge
  snapshot_merge_next_chunks // COW 设备中的数据落盘
   persistent_prepare_merge // s->store->type->prepare_merge 获取当前 area 中，从后向前连续的待同步的 chunk 数，以及最后一组映射chunks
   read_pending_exceptions_done_count // 获取已完成的 pending exception 计数
   origin_write_extent
    __lookup_origin // 查找原始设备
    __origin_write // 遍历当前原始设备的 snapshot 链表中所有的 snapshot，如果特定位置有 pending exception 未完成，则需等待
   read_pending_exceptions_done_count // 如果 snapshot 特定位置有 pending exception 则获取已完成的 pending exception 计数，等待 pending exception 完成，再次尝试
   __check_for_conflicting_io // 等待所有相关 chunk 停止 track，即相关 chunk 没有进程在下IO
   dm_kcopyd_copy
    mempool_alloc // 分配初始化 kcopyd_job
    dispatch_job
     push // kcopyd_job 加入 dm_kcopyd_client->pages_jobs
     wake // dm_kcopyd_client
      queue_work // dm_kcopyd_client->kcopyd_work 加入 dm_kcopyd_client->kcopyd_wq

<--------------------------------- 1、从 COW 设备读待修改区域的数据 --------------------------------->
do_work
 // 第一次调用 do_work 不会下发原始IO到原始设备，此时 complete_jobs 为空，在备份完成，在 complete_io 中添加 job 到 complete_jobs
 process_jobs // 处理 pages_jobs
  run_pages_job
   kcopyd_get_pages
   push // kcopyd_job 加入 dm_kcopyd_client->io_jobs
 process_jobs // 处理 io_jobs
  run_io_job
   io_job_start // 根据 dm_kcopyd_client->throttle 限速
   dm_io
    async_io
	 dispatch_io
	  do_region // 从 COW 设备中读数据到内存
      dec_count
	   complete_io // drivers\md\dm-io.c
	    complete_io //io->callback --> fn --> io_req->notify.fn --> complete_io (drivers\md\dm-kcopyd.c)
		 push // 写 job push 到 io_jobs

<--------------------------------- 2、将读取的数据恢复到原始设备 --------------------------------->
do_work
 process_jobs // 处理 io_jobs
  run_io_job
   dm_io
    async_io
	 dispatch_io
	  do_region // 从内存中将数据恢复到原始设备
      dec_count
	   complete_io // drivers\md\dm-io.c
	    complete_io //io->callback --> fn --> io_req->notify.fn --> complete_io (drivers\md\dm-kcopyd.c)
		 push // job push 到 complete_jobs

<--------------------------------- 3、相关变量更新 --------------------------------->
do_work
 process_jobs // 处理 complete_jobs
  run_complete_job
   merge_callback // fn
    flush_data
	 submit_bio_wait // 对原始设备下发 flush
	 persistent_commit_merge // s->store->type->commit_merge
	  clear_exception // 清空当前 area 中已经回写的 exception
	  area_io // 回写当前 area 的第一个 chunk
	  area_location // 更新 pstore->next_free
	remove_single_exception_chunk // 处理当前 chunks 的最后一个 chunk
	 __remove_single_exception_chunk
	  dm_lookup_exception // 查找当前 chunk 所在的 exception
	  // 1、如果是单 chunk，直接移除
	  dm_consecutive_chunk_count
	  // 2、如果是当前 exception 的第一个 chunk，则把 exception 的第一个 chunk 索引 ++
	  dm_consecutive_chunk_count_dec // 缩小映射范围
	  // 3、如果不是当前 exception 的最后一个 chunk，则返错
	 __release_queued_bios_after_merge // 处理 merge 过程中下发的写IO
	 flush_bios
	  submit_bio_noacct // 直接下发IO
	snapshot_merge_next_chunks // 下一轮
```
### （3）snapshot_merge_map

[snapshot_merge_map](#snapshot_merge_map)

## 4、使用场景

### （1）典型使用场景
![image](https://github.com/user-attachments/assets/47fb28a1-a454-4b19-a948-32318398161e)
#### 1、创建物理卷

```
pvcreate /dev/sda
```

#### 2、创建卷组

```
vgcreate volumeGroup /dev/sda
```

#### 3、创建 base 盘（linear 设备）

```
lvcreate -L 1G -n base volumeGroup
[root@localhost ~]# lsblk
NAME               MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                  8:0    0   10G  0 disk
└─volumeGroup-base 252:0    0    1G  0 lvm
sdb                  8:16   0    5G  0 disk
sdc                  8:32   0   20G  0 disk
sdd                  8:48   0   30G  0 disk
sde                  8:64   0  100G  0 disk
sdf                  8:80   0    8M  0 disk
vda                253:0    0   20G  0 disk /

[root@localhost ~]# dmsetup table
volumeGroup-base: 0 2097152 linear 8:0 2048
[root@localhost ~]#
volumeGroup-base-real: 0 2097152 linear 8:0 2048

/*
 * 创建 linear 设备，映射到物理盘
 */
```

#### 4、格式化使用 base 盘

```
mkfs.ext4 /dev/mapper/volumeGroup-base

[root@localhost ~]# mount /dev/mapper/volumeGroup-base /mnt/test
[root@localhost ~]# echo 123-base > /mnt/test/testfile
[root@localhost ~]# cat /mnt/test/testfile
123-base
[root@localhost ~]#
```

#### 5、创建 snapshot

```
lvcreate -L 100M --snapshot -n snap volumeGroup/base
[root@localhost ~]# lsblk
NAME                    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                       8:0    0   10G  0 disk
├─volumeGroup-base-real 252:1    0    1G  0 lvm
│ ├─volumeGroup-base    252:0    0    1G  0 lvm  /mnt/test
│ └─volumeGroup-snap    252:3    0    1G  0 lvm
└─volumeGroup-snap-cow  252:2    0  100M  0 lvm
  └─volumeGroup-snap    252:3    0    1G  0 lvm
sdb                       8:16   0    5G  0 disk
sdc                       8:32   0   20G  0 disk
sdd                       8:48   0   30G  0 disk
sde                       8:64   0  100G  0 disk
sdf                       8:80   0    8M  0 disk
vda                     253:0    0   20G  0 disk /

[root@localhost ~]# dmsetup table
volumeGroup-snap-cow: 0 204800 linear 8:0 2099200
volumeGroup-snap: 0 2097152 snapshot 252:1 252:2 P 8
volumeGroup-base-real: 0 2097152 linear 8:0 2048
volumeGroup-base: 0 2097152 snapshot-origin 252:1

/*
 * 1) 创建 linear 设备 base-real ，与 base 映射物理盘的 map 一致
 * DM_DEV_CREATE name="volumeGroup-base-real" 252:1
 * DM_TABLE_LOAD 252:1 -- 8:0 2048
 * base reload 成 snapshot-origin，reload 到 base-real
 * DM_TABLE_LOAD 252:0 -- 252:1
 * --> 之后对 base 设备的操作，不会直接写入物理盘
 *
 * 2) 创建 linear 设备 snap-cow
 * DM_DEV_CREATE name="volumeGroup-snap-cow" 252:2
 * DM_TABLE_LOAD 252:2 -- 8:0 2099200
 * --> 用于写 snapshot
 *
 * 3) 创建 snapshot 设备 snap，load 到 base-real 和 snap-cow
 * DM_DEV_CREATE name="volumeGroup-snap"
 * DM_TABLE_LOAD 252:3 -- 252:1 252:2 P 8
 */
```

#### 6、使用 snapshot

```
// 挂载使用 snapshot （读）
[root@localhost ~]# mount /dev/mapper/volumeGroup-snap /mnt/test_sda
[root@localhost ~]# ls /mnt/test_sda/
lost+found  testfile
[root@localhost ~]# cat /mnt/test_sda/testfile
123-base
[root@localhost ~]# lsblk
NAME                    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                       8:0    0   10G  0 disk
├─volumeGroup-base-real 252:1    0    1G  0 lvm
│ ├─volumeGroup-base    252:0    0    1G  0 lvm  /mnt/test
│ └─volumeGroup-snap    252:3    0    1G  0 lvm  /mnt/test_sda
└─volumeGroup-snap-cow  252:2    0  100M  0 lvm
  └─volumeGroup-snap    252:3    0    1G  0 lvm  /mnt/test_sda
sdb                       8:16   0    5G  0 disk
sdc                       8:32   0   20G  0 disk
sdd                       8:48   0   30G  0 disk
sde                       8:64   0  100G  0 disk
sdf                       8:80   0    8M  0 disk
vda                     253:0    0   20G  0 disk /
[root@localhost ~]#
[root@localhost ~]# dmsetup table
volumeGroup-snap-cow: 0 204800 linear 8:0 2099200
volumeGroup-snap: 0 2097152 snapshot 252:1 252:2 P 8
volumeGroup-base-real: 0 2097152 linear 8:0 2048
volumeGroup-base: 0 2097152 snapshot-origin 252:1
[root@localhost ~]#

// 使用 snapshot-origin （写） --> 数据落盘
[root@localhost ~]# echo 123-snap-ori > /mnt/test/testfile1
[root@localhost ~]# ls /mnt/test
lost+found  testfile  testfile1

// 不影响 snapshot （读） --> 从 base 设备中读取
[root@localhost ~]# ls /mnt/test_sda
lost+found  testfile
[root@localhost ~]#
[root@localhost ~]# cat /mnt/test/testfile1
123-snap-ori
[root@localhost ~]#
```

#### 7、删除 snapshot

```
base-real(linear): testfile
base(snapshot-origin): testfile testfile1
```

##### a. remove snapshot

```
remove snapshot
lvremove volumeGroup/snap

[root@localhost ~]# umount /mnt/test
[root@localhost ~]# umount /mnt/test_sda
[root@localhost ~]# lvremove volumeGroup/snap
File descriptor 6 (/dev/ttyS0) leaked on lvremove invocation. Parent PID 2449: -bash
Do you really want to remove active logical volume volumeGroup/snap? [y/n]: y
  Logical volume "snap" successfully removed
[root@localhost ~]# lsblk
NAME               MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                  8:0    0   10G  0 disk
└─volumeGroup-base 252:0    0    1G  0 lvm
sdb                  8:16   0    5G  0 disk
sdc                  8:32   0   20G  0 disk
sdd                  8:48   0   30G  0 disk
sde                  8:64   0  100G  0 disk
sdf                  8:80   0    8M  0 disk
vda                253:0    0   20G  0 disk /
[root@localhost ~]# dmsetup table
volumeGroup-base: 0 2097152 linear 8:0 2048

/*
 * DM_TABLE_LOAD 252:0(base) -- 8:0 2048
 * DM_TABLE_LOAD 252:3(snap) -- 8:0 2099200(volumeGroup-snap-cow)
 * DM_DEV_SUSPEND(suspend) 252:0
 * DM_DEV_SUSPEND(suspend) 252:3
 * DM_DEV_SUSPEND(suspend) 252:1
 * DM_DEV_SUSPEND(suspend) 252:2
 * DM_DEV_SUSPEND(resume) 252:2
 * DM_DEV_SUSPEND(resume) 252:1
 * DM_DEV_SUSPEND(resume) 252:3
 * DM_DEV_REMOVE 252:2
 * DM_DEV_SUSPEND(resume) 252:0
 * DM_DEV_REMOVE 252:1
 * DM_DEV_REMOVE 252:3
 */

// 查看 base 设备，对 snapshot-origin 的修改落盘
[root@localhost ~]# mount /dev/mapper/volumeGroup-base /mnt/test
[root@localhost ~]# ls /mnt/test
lost+found  testfile  testfile1
[root@localhost ~]# cat /mnt/test/testfile
123-base
[root@localhost ~]# cat /mnt/test/testfile1
123-snap-ori
[root@localhost ~]#
```

##### b. merge snapshot

```
[root@localhost ~]# lsblk
NAME                    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                       8:0    0   10G  0 disk
├─volumeGroup-base-real 252:1    0    1G  0 lvm
│ ├─volumeGroup-base    252:0    0    1G  0 lvm  /mnt/test
│ └─volumeGroup-snap    252:3    0    1G  0 lvm  /mnt/test_sda
└─volumeGroup-snap-cow  252:2    0  100M  0 lvm
  └─volumeGroup-snap    252:3    0    1G  0 lvm  /mnt/test_sda
sdb                       8:16   0    5G  0 disk
sdc                       8:32   0   20G  0 disk
sdd                       8:48   0   30G  0 disk
sde                       8:64   0  100G  0 disk
sdf                       8:80   0    8M  0 disk
vda                     253:0    0   20G  0 disk /
[root@localhost ~]# ls /mnt/test
lost+found  testfile  testfile1
[root@localhost ~]# ls /mnt/test_sda/
lost+found  testfile  testfile1

// 修改 snapshot-origin
[root@localhost ~]# echo 123-snap-oritest > /mnt/test/testfile2
[root@localhost ~]# ls /mnt/test
lost+found  testfile  testfile1  testfile2
[root@localhost ~]#

// merge snap 设备
[root@localhost ~]# umount /mnt/test
[root@localhost ~]# umount /mnt/test_sda
[root@localhost ~]# lvconvert --merge volumeGroup/snap
File descriptor 6 (/dev/ttyS0) leaked on lvconvert invocation. Parent PID 2449: -bash
  Merging of volume volumeGroup/snap started.
  base: Merged: 100.00%
[root@localhost ~]# lsblk
NAME               MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                  8:0    0   10G  0 disk
└─volumeGroup-base 252:0    0    1G  0 lvm
sdb                  8:16   0    5G  0 disk
sdc                  8:32   0   20G  0 disk
sdd                  8:48   0   30G  0 disk
sde                  8:64   0  100G  0 disk
sdf                  8:80   0    8M  0 disk
vda                253:0    0   20G  0 disk /
[root@localhost ~]#

/*
 * DM_TABLE_LOAD 252:0 -- 252:1 252:2 P 8 snapshot-merge
 * DM_TABLE_LOAD 252:3 -- error
 * DM_DEV_SUSPEND(suspend) 252:0
 * DM_DEV_SUSPEND(suspend) 252:3
 * DM_DEV_SUSPEND(suspend) 252:1
 * DM_DEV_SUSPEND(suspend) 252:2
 * DM_DEV_SUSPEND(resume) 252:1
 * DM_DEV_SUSPEND(resume) 252:2
 * DM_DEV_SUSPEND(resume) 252:0
 * DM_DEV_SUSPEND(resume) 252:3
 */

// 挂载查看 --> snapshot-origin 上的修改已丢弃 --> 快照恢复
[root@localhost ~]# mount /dev/mapper/volumeGroup-base /mnt/test
[root@localhost ~]# ls /mnt/test
lost+found  testfile  testfile1
[root@localhost ~]#
```

### （2）多快照场景

#### 1、 并行创建多快照

(所有快照均基于base设备)

```
lvcreate -L 1G -n base volumeGroup // 创建 base
// base 写 1
lvcreate -L 100M --snapshot -n snap volumeGroup/base // snap 数据为 1
// base 写 2
lvcreate -L 100M --snapshot -n snap2 volumeGroup/base // snap2 数据为 2
// base 写 3
lvconvert --merge volumeGroup/snap
// base 数据恢复成 1， snap2 数据仍为 2
lvconvert --merge volumeGroup/snap2
// base 数据恢复成 2

snap 角度 base 数据变化：
1(初始值) --> 2(数据更新) --> 3(数据更新) --> 1(数据恢复)
snap2 角度 base 数据变化：
1(初始值) --> 2(数据更新) --> 3(数据更新) --> 1(数据更新) --> 2(数据恢复)

// 设备拓扑
[root@localhost ~]# lsblk
NAME                    MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda                       8:0    0   10G  0 disk
├─volumeGroup-base-real 252:1    0    1G  0 lvm
│ ├─volumeGroup-base    252:0    0    1G  0 lvm
│ ├─volumeGroup-snap    252:3    0    1G  0 lvm
│ └─volumeGroup-snap2   252:5    0    1G  0 lvm
├─volumeGroup-snap-cow  252:2    0  100M  0 lvm
│ └─volumeGroup-snap    252:3    0    1G  0 lvm
└─volumeGroup-snap2-cow 252:4    0  100M  0 lvm
  └─volumeGroup-snap2   252:5    0    1G  0 lvm
vda                     253:0    0   20G  0 disk /
[root@localhost ~]#
<---------------- 多个快照恢复 不需要 按照创建的相反顺序 ---------------->
```



#### 2、串行创建多快照

(后面的快照基于前面的快照创建)

```
dmsetup create base-real --table "0 2097152 linear /dev/sda 0"
dmsetup create cow --table "0 204800 linear /dev/sda 2099200"
dmsetup create cow2 --table "0 204800 linear /dev/sda 2304000"
dmsetup create base --table "0 2097152 snapshot-origin /dev/mapper/base-real"
// base 写 1
dmsetup create snap --table "0 2097152 snapshot /dev/mapper/base-real /dev/mapper/cow P 8" // snap 数据为 1
// base 写 2 -- cow 上保存 1
dmsetup create snap2 --table "0 2097152 snapshot /dev/mapper/snap /dev/mapper/cow2 P 8" // snap2 数据为 1
// snap 写 3 -- cow 上保存 3，由于 snap 不是 snapshot-origin 类型设备，cow2 上不保存数据
// snap2 写 4 -- cow2 上保存 4

<---------- 不按照创建的相反顺序 ---------->
dmsetup suspend snap
dmsetup create snap-merge --table "0 2097152 snapshot-merge /dev/mapper/base-real /dev/mapper/cow P 8" // merge snap -- base 从 2 恢复成 3

[root@localhost ~]# dmsetup status
snap-merge: 0 2097152 snapshot-merge 16/204800 16
snap: 0 2097152 snapshot Invalid
snap2: 0 2097152 snapshot 24/204800 16
cow2: 0 204800 linear
base: 0 2097152 snapshot-origin
cow: 0 204800 linear
base-real: 0 2097152 linear
[root@localhost ~]#

此时 snap 被设置成 invalid，不能读写，也就不能再用 snap2 去恢复 snap

<---------- 按照创建的相反顺序 ---------->
dmsetup suspend snap2
dmsetup create snap2-merge --table "0 2097152 snapshot-merge /dev/mapper/snap /dev/mapper/cow2 P 8" // merge snap2 -- snap 从 3 恢复成 4
dmsetup remove /dev/mapper/snap2-merge
dmsetup remove /dev/mapper/snap2
dmsetup create snap-merge --table "0 2097152 snapshot-merge /dev/mapper/base-real /dev/mapper/cow P 8" // merge snap -- base 从 2 恢复成 4
dmsetup remove /dev/mapper/snap-merge
dmsetup remove /dev/mapper/snap

// 设备拓扑
[root@localhost ~]# lsblk
NAME        MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda           8:0    0   10G  0 disk
├─base-real 252:0    0    1G  0 dm
│ ├─base    252:3    0    1G  0 dm   /mnt/test
│ └─snap    252:4    0    1G  0 dm
│   └─snap2 252:5    0    1G  0 dm
├─cow       252:1    0  100M  0 dm
│ └─snap    252:4    0    1G  0 dm
│   └─snap2 252:5    0    1G  0 dm
└─cow2      252:2    0  100M  0 dm
  └─snap2   252:5    0    1G  0 dm
vda         253:0    0   20G  0 disk /
[root@localhost ~]#
<---------------- 多个快照恢复 需要 按照创建的相反顺序 ---------------->
```



## 5、关键结构

```c
                          [index by chunk]
                           hlist_bl_head
                          ┌──────────────┬──────────┬─────────┬────────────┐
snapshot->pending->table  │              │          │         │ ......     │
snapshot->complete->table └──────┬───────┴──────────┴─────────┴────────────┘
                                 │
                               ┌─┴─┐
                               │   │
                               └─┬─┘
                                 │
                               ┌─┴─┐
             sort by old_chunk │   │
                               └─┬─┘
                                 │                             dm_exception
                               ┌─┴─┐                          ┌─────────┐
                               │   │ ◄────hlist_bl_node ◄─────┤hash_list│
                               └─┬─┘                          │old_chunk│
                                 │                            │new_chunk│
                                 │                            └─────────┘
                                 .
                                 .
                                 .
// 索引函数：dm_lookup_exception()
```

```c
// 以 origin 设备的 block_device 的 hash 作为数组索引
// 每一个数组成员，是一个链表，链表元素是 struct origin 的list_head(同一链表上的origin设备的block_device的hash相同)
// 每个 origin 设备有一个 snapshot 链表，在给某个origin设备第一次注册snapshot时会分配 struct origin 插入链表中，同时当前注册的snapshot会插入snapshot链表中，后续再给这个origin设备注册snapshot时直接插入链表中
       [index by block_device]
             list_head
         ┌──────────────┬──────────┬─────────┬────────────┐
_origins │              │          │         │ ......     │
         └──────┬───────┴──────────┴─────────┴────────────┘
                │
              ┌─┴─┐
              │   │  different block_device(used as origin)
              └─┬─┘  with same hash
                │
              ┌─┴─┐
              │   │  check bdev_equal(o->bdev, origin)
              └─┬─┘  to find exact node
                │                             origin
              ┌─┴─┐                          ┌─────────┐
              │   │ ◄────block_device  ◄─────┤bdev     │
              └─┬─┘                          │hash_list│  ┌───┐  ┌───┐
                │                            │snapshots├──┤   ├──┤   ├───......
                │                            └─────────┘  └───┘  └───┘
                .                                      dm_snapshot
                .
                .
// 索引函数：__lookup_origin
// 每个 origin 可以对应多个 snapshot
```

```c
// 以 origin 设备的底层设备的 block_device 的 hash 作为数组索引
// 每一个数组成员，是一个链表，链表元素是 struct dm_origin 的list_head(同一链表上的origin设备的底层设备的block_device的hash相同)
// 在 origin 设备创建时(origin_ctr)分配 dm_origin，同时关联底层设备；在 origin 设备resume时(origin_resume)插入对应的链表
          [index by block_device]
                list_head
            ┌──────────────┬──────────┬─────────┬────────────┐
_dm_origins │              │          │         │ ......     │
            └──────┬───────┴──────────┴─────────┴────────────┘
                   │
                 ┌─┴─┐
                 │   │  different block_device
                 └─┬─┘  with same hash
                   │
                 ┌─┴─┐
                 │   │  check bdev_equal(o->bdev, origin)
                 └─┬─┘  to find exact node
                   │                             dm_origin
                 ┌─┴─┐                          ┌─────────┐
                 │   │ ◄────block_device  ◄─────┤dev      │     ┌──────────┐
                 └─┬─┘                          │ti       ├────►│dm_target │
                   │                            │hash_list│     └──────────┘
                   │                            └─────────┘
                   .
                   .
                   .
// 索引函数：__lookup_dm_origin
// 每个 dm_origin 只对应一个 dm_target
```



```c
struct dm_snapshot {
	struct rw_semaphore lock;

	struct dm_dev *origin;
	struct dm_dev *cow;

	struct dm_target *ti;

	/* List of snapshots per Origin */
	struct list_head list;

	/*
	 * You can't use a snapshot if this is 0 (e.g. if full).
	 * A snapshot-merge target never clears this.
	 */
	int valid;

	/*
	 * The snapshot overflowed because of a write to the snapshot device.
	 * We don't have to invalidate the snapshot in this case, but we need
	 * to prevent further writes.
	 */
	int snapshot_overflowed;

	/* Origin writes don't trigger exceptions until this is set */
	int active;

	atomic_t pending_exceptions_count;

	spinlock_t pe_allocation_lock;

	/* Protected by "pe_allocation_lock" */
	sector_t exception_start_sequence;

	/* Protected by kcopyd single-threaded callback */
	sector_t exception_complete_sequence;

	/*
	 * A list of pending exceptions that completed out of order.
	 * Protected by kcopyd single-threaded callback.
	 */
	struct rb_root out_of_order_tree;

	mempool_t pending_pool;

    // 两个数组，每个数组元素是一个 dm_exception 链表，根据 old_chunk(即原始设备上的 chunk 偏移) 计算索引
    // dm_exception 记录 old_chunk 和 new_chunk(即 COW 设备上的 chunk 偏移)的映射关系
    // pending：当前映射关系正被用于下IO
    // complete：已有IO利用当前映射关系完成了
	struct dm_exception_table pending;
	struct dm_exception_table complete;

	/*
	 * pe_lock protects all pending_exception operations and access
	 * as well as the snapshot_bios list.
	 */
	spinlock_t pe_lock;

	/* Chunks with outstanding reads */
	spinlock_t tracked_chunk_lock;
	struct hlist_head tracked_chunk_hash[DM_TRACKED_CHUNK_HASH_SIZE];

	/* The on disk metadata handler */
	struct dm_exception_store *store;

	unsigned in_progress;
	struct wait_queue_head in_progress_wait;

	struct dm_kcopyd_client *kcopyd_client;

	/* Wait for events based on state_bits */
	unsigned long state_bits;

	/* Range of chunks currently being merged. */
	chunk_t first_merging_chunk;
	int num_merging_chunks;

	/*
	 * The merge operation failed if this flag is set.
	 * Failure modes are handled as follows:
	 * - I/O error reading the header
	 *   	=> don't load the target; abort.
	 * - Header does not have "valid" flag set
	 *   	=> use the origin; forget about the snapshot.
	 * - I/O error when reading exceptions
	 *   	=> don't load the target; abort.
	 *         (We can't use the intermediate origin state.)
	 * - I/O error while merging
	 *	=> stop merging; set merge_failed; process I/O normally.
	 */
	bool merge_failed:1;

	bool discard_zeroes_cow:1;
	bool discard_passdown_origin:1;

	/*
	 * Incoming bios that overlap with chunks being merged must wait
	 * for them to be committed.
	 */
	struct bio_list bios_queued_during_merge;

	/*
	 * Flush data after merge.
	 */
	struct bio flush_bio;
};

```

## 6、关键函数

### （1）snapshot_resume

```c
static void snapshot_resume(struct dm_target *ti)
{
	struct dm_snapshot *s = ti->private;
	struct dm_snapshot *snap_src = NULL, *snap_dest = NULL, *snap_merging = NULL;
	struct dm_origin *o;
	struct mapped_device *origin_md = NULL;
	bool must_restart_merging = false;

	down_read(&_origins_lock);

	o = __lookup_dm_origin(s->origin->bdev); // 查找当前的 real 设备是否有 snapshot-origin
	if (o)
		origin_md = dm_table_get_md(o->ti->table); // 获取 snapshot-origin 的 mapped_device
	if (!origin_md) { // 获取不到，说明没有 snapshot-origin -- 1. 未创建 snapshot-origin; 2. 已经开始 merge，snapshot-origin 已删除
		(void) __find_snapshots_sharing_cow(s, NULL, NULL, &snap_merging); // 查找 snapshot-merge 设备
		if (snap_merging)
			origin_md = dm_table_get_md(snap_merging->ti->table); // 查到了，则获取 snapshot-merge 设备的 mapped_device
	}
	/*
	 * origin_md:
	 * 1. NULL -- 从未有过 snapshot-origin
	 * 2. snapshot-origin 的 mapped_device
	 * 3. snapshot-merge 的 mapped_device
	 */

	/*
	 * 1. 当前是 snapshot 的 resume 流程
	 *    处理：条件不成立， origin_md 为 NULL(没创建过 snapshot-origin)， 不为 NULL(创建过 snapshot-origin)
	 *    后续：需要 suspend origin，如果正在进行 merging，需要停止 merging，因为在创建 snapshot 的过程中，要保证原始设备不被修改。
	 *          写 snapshot-origin 会修改原始设备数据，merging 也会修改原始设备数据
	 * 2. 当前是 snapshot-merge 的 resume 流程
	 *    处理：条件成立， origin_md 置 NULL
	 *    后续：无需做特殊处理
	 */

	if (origin_md == dm_table_get_md(ti->table))
		origin_md = NULL;
	if (origin_md) {
		if (dm_hold(origin_md))
			origin_md = NULL;
	}

	up_read(&_origins_lock);

	if (origin_md) {
		dm_internal_suspend_fast(origin_md);
		if (snap_merging && test_bit(RUNNING_MERGE, &snap_merging->state_bits)) {
			must_restart_merging = true;
			stop_merge(snap_merging);
		}
	}

	down_read(&_origins_lock);

	(void) __find_snapshots_sharing_cow(s, &snap_src, &snap_dest, NULL);
	/* 
	 * 查找到两个使用相同cow设备的snapshot -- snap_src snap_dest
	 * 当前 resume 流程中的snapshot，必然是 snap_src snap_dest 中的一个
	 * 创建流程中会注册 snapshot，register_snapshot 中会检查使用相同cow设备的snapshot数量，如果达到两个，则注册失败
	 * 当前 snapshot 必然已注册成功，因此必然是上述两个 snapshot 其中一个
	 *
	 * 1、两个 shapshot(结构体) 都是 snapshot(设备类型)
	 * 2、两个 snapshot(结构体)，一个是 snapshot(设备类型)，另一个是 snapshot-merge
	 */

	if (snap_src && snap_dest) {
		down_write(&snap_src->lock);
		down_write_nested(&snap_dest->lock, SINGLE_DEPTH_NESTING);
		__handover_exceptions(snap_src, snap_dest);
		up_write(&snap_dest->lock);
		up_write(&snap_src->lock);
	}

	up_read(&_origins_lock);

	/*
	 * 恢复 merging 进程，resume 之前被 suspend 的 snapshot-origin
	 */
	if (origin_md) {
		if (must_restart_merging)
			start_merge(snap_merging);
		dm_internal_resume_fast(origin_md);
		dm_put(origin_md);
	}

	/* s 可能作为 snap_dest 接收了 snap_src 的 chunk size，因此需要按照 chunk size 大小顺序重新插入链表注册 */
	/* Now we have correct chunk size, reregister */
	reregister_snapshot(s);

	down_write(&s->lock);
	s->active = 1;
	up_write(&s->lock);
}
```

### （2）persistent_prepare_merge

```c
static int persistent_prepare_merge(struct dm_exception_store *store,
				    chunk_t *last_old_chunk,
				    chunk_t *last_new_chunk)
{
	struct pstore *ps = get_info(store);
	struct core_exception ce;
	int nr_consecutive;
	int r;

	/*
	 * When current area is empty, move back to preceding area.
	 */
	/*
	 * 当前 area 的下一个空闲 exception 为0，即 current_area 为空
	 */
	if (!ps->current_committed) {
		/*
		 * Have we finished?
		 */
		/*
		 * 如果当前 area 索引是0，说明 merge 已经完成，没有需要 merge 的内容
		 * 如果当前 area 索引非0，需回到上一个有非空闲 exception 的 area，查找待 merge 的数据
		 */
		if (!ps->current_area)
			return 0;

		ps->current_area--;
		r = area_io(ps, REQ_OP_READ, 0);
		if (r < 0)
			return r;
		/*
		 * 回到上一个 area，该 area 满，下一个空闲 exception 的索引应为 exceptions_per_area
		 */
		ps->current_committed = ps->exceptions_per_area;
	}
	/*
	 * 最后一个待同步的 chunk
	 */
	read_exception(ps, ps->area, ps->current_committed - 1, &ce);
	*last_old_chunk = ce.old_chunk;
	*last_new_chunk = ce.new_chunk;

	/*
	 * Find number of consecutive chunks within the current area,
	 * working backwards.
	 */
	for (nr_consecutive = 1; nr_consecutive < ps->current_committed;
	     nr_consecutive++) {
		read_exception(ps, ps->area,
			       ps->current_committed - 1 - nr_consecutive, &ce);
		if (ce.old_chunk != *last_old_chunk - nr_consecutive ||
		    ce.new_chunk != *last_new_chunk - nr_consecutive)
			break;
	}

	return nr_consecutive;
}
```

### （3）dm_insert_exception -- 插入exception

```c
dm_consecutive_chunk_count & dm_consecutive_chunk_count_inc
// dm_exception->new_chunk 的映射范围与实际 new_chunk 的边界
DM_CHUNK_NUMBER_BITS
// 获取映射长度
dm_consecutive_chunk_count
// 增加映射长度
dm_consecutive_chunk_count_inc

static void dm_insert_exception(struct dm_exception_table *eh,
				struct dm_exception *new_e)
{
	struct hlist_bl_head *l;
	struct hlist_bl_node *pos;
	struct dm_exception *e = NULL;

	l = &eh->table[exception_hash(eh, new_e->old_chunk)];

	/* Add immediately if this table doesn't support consecutive chunks */
	if (!eh->hash_shift)
		goto out;

	/* List is ordered by old_chunk */
	hlist_bl_for_each_entry(e, pos, l, hash_list) {
		/* Insert after an existing chunk? */
        /* 如果待添加的映射的 old_chunk 刚好在某个 exception 的映射范围后一个 */
		/* 则向后扩大已有映射的范围，包含已有映射和新的映射 */
		if (new_e->old_chunk == (e->old_chunk +
					 dm_consecutive_chunk_count(e) + 1) &&
		    new_e->new_chunk == (dm_chunk_number(e->new_chunk) +
					 dm_consecutive_chunk_count(e) + 1)) {
			dm_consecutive_chunk_count_inc(e);
			free_completed_exception(new_e);
			return;
		}

		/* Insert before an existing chunk? */
        /* 同上，向前扩大已有映射的范围 */
		if (new_e->old_chunk == (e->old_chunk - 1) &&
		    new_e->new_chunk == (dm_chunk_number(e->new_chunk) - 1)) {
			dm_consecutive_chunk_count_inc(e);
			e->old_chunk--;
			e->new_chunk--;
			free_completed_exception(new_e);
			return;
		}

		if (new_e->old_chunk < e->old_chunk)
			break;
	}

out:
	if (!e) {
		/*
		 * Either the table doesn't support consecutive chunks or slot
		 * l is empty.
		 */
		hlist_bl_add_head(&new_e->hash_list, l);
	} else if (new_e->old_chunk < e->old_chunk) {
		/* Add before an existing exception */
		hlist_bl_add_before(&new_e->hash_list, &e->hash_list);
	} else {
		/* Add to l's tail: e is the last exception in this slot */
		hlist_bl_add_behind(&new_e->hash_list, &e->hash_list);
	}
}
```

### （4）**dm_lookup_exception -- 查找 exception**

```c
static struct dm_exception *dm_lookup_exception(struct dm_exception_table *et,
						chunk_t chunk)
{
	struct hlist_bl_head *slot;
	struct hlist_bl_node *pos;
	struct dm_exception *e;

	slot = &et->table[exception_hash(et, chunk)];
	hlist_bl_for_each_entry(e, pos, slot, hash_list)
        // 待查找的 chunk 是否在当前 exception 的映射范围内
        // 通过 dm_consecutive_chunk_count(e) 获取当前 exception 的映射长度
		if (chunk >= e->old_chunk &&
		    chunk <= e->old_chunk + dm_consecutive_chunk_count(e))
			return e;

	return NULL;
}
```

