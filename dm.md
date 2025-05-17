
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
