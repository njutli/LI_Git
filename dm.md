
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
