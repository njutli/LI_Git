dm-thin-pool

**超级块磁盘结构**
```
struct thin_disk_superblock {
__le32 csum; /* Checksum of superblock except for this field. */
__le32 flags;
__le64 blocknr; /* This block number, dm_block_t. */
__u8 uuid[16];
__le64 magic; # pool设备的magic: 27022010
__le32 version;
__le32 time;
__le64 trans_id;
/*
* Root held by userspace transactions.
*/
__le64 held_root;
/* 保存数据盘的数据位图信息，即标记数据盘上的那个块被使用了，哪些块是空闲的 */
__u8 data_space_map_root[SPACE_MAP_ROOT_SIZE];
/* 保存元数据盘的数据位图信息，即标记元数据盘上的那个块被使用了，哪些块是空闲的 */
__u8 metadata_space_map_root[SPACE_MAP_ROOT_SIZE]; # 保存元数据位图btree的root节点的块号
/*
* 2‐level btree mapping (dev_id, (dev block, time)) ‐> data block
*/
/* 两层的B树，保存数据块的映射信息，即 thin 的第n个扇区，映射到元数据盘上的第m个扇区 */
__le64 data_mapping_root;
/* 用于保存所有 thin 设备的信息 */
__le64 device_details_root;
__le32 data_block_size; # 数据设备块大小 = data_block_size * 512B
__le32 metadata_block_size; /* In 512‐byte sectors. */
__le64 metadata_nr_blocks;
__le32 compat_flags;
__le32 compat_ro_flags;
__le32 incompat_flags;
} __packed;
```
