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

```
从 thin 1 中读取块1的映射过程：
1. 从元数据盘中获取超级块信息，获取到空间映射信息 data_mapping_root 保存在 70 号块中
2. 从70号块中查找 thin 1 的空间映射信息保存在元数据盘的 54 号块中
3. 从54号块中获取空间映射信息，发现该节点的flag=LEAF_NODE，为叶子节点，说明该节点的key:value即为映射信息
4. 从54号块中获取的 key[1] = 1,即 thin 1 的逻辑块号 1 映射到物理块号 value[1] = 113 号块中。
从 thin 2 中读取720的流程
1. 从元数据盘中获取超级块信息，获取到空间映射信息 data_mapping_root 保存在 70 号块中
2. 从70号块中查找 thin 2 的空间映射信息保存在元数据盘的 17 号块中
3. 从17号块中获取空间映射信息，发现该节点的flag=INTERNAL_NODE，为内部节点，说明该节点的key:value为间接映射信息
4. 查找到 key[1] = 638 < 720 < key[2] = 4720，因此，可以确认 thin 2 的 720 号块的映射信息保存在 value[1] = 69 号块中。
5. 从69号块中获取到 key[2] = 720，即 thin 2 的逻辑块号 720 映射到物理块号 value[2] = 110 号块中。
创建 thin 12 的流程
1. 从元数据盘中获取超级块信息，获取到设备信息保存在 59 号块中
2. 从 59 号块中查找，不存在 key[x] = 12，即说明 thin 12 不存在，可以创建
3. 修改内存中59号块的数据，添加 thin 12 的设备信息，并将其更新到新块中，此处举例为 50
4. 修改元数据盘位图信息，标记50号块和新的元数据盘位图信息 11 号块为占用状态，老的元数据盘位图信息 74 号块 和老的设备信息 59 号块为 空闲状态
5. 将其覆盖，即完成该操作。
```
