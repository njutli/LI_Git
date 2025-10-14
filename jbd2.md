#### 概念：jbd2的由来
ex2  ->  ext3  ->ext4
none ->  jbd   ->jbd2

#### 作用：保证文件系统一致性
原理：当内核要修改元数据块或者数据块时候，会将其写入日志区；如果此时系统奔溃，文件系统出现不一致问题，可以通过日志区记录的信息进行日志重演；

#### 日志区
日志区是循环的；
日志区是中保存着需要重演的元数据或者数据；
日志区可以是一个设备或者是文件系统中一个inode（常见的是<8>）；

#### 日志模式
挂载的时候使用data=***来选择模式；
- journal
元数据和数据都会被先写入到日志区，然后再写入磁盘上；
- ordered
只存元数据，元数据和数据会被记在同一个事务中。在日志提交时保证数据先与元数据落盘；
- writeback
不保证数据比元数据先落盘；

后面主要讲解ordered模式；

#### 日志区on-disk结构
[on-disk结构](https://note.youdao.com/s/FlGZwjqI)

#### 日志创建
[日志落盘](https://note.youdao.com/s/bnWclZ7u)


#### 文献
[参考](https://blog.csdn.net/qq_22613757/article/details/86571646?ops_request_misc=%257B%2522request%255Fid%2522%253A%2522166000873216780366582640%2522%252C%2522scm%2522%253A%252220140713.130102334..%2522%257D&request_id=166000873216780366582640&biz_id=0&utm_medium=distribute.pc_search_result.none-task-blog-2)



#### 日志区总体结构

![image.png](https://note.youdao.com/yws/res/673/WEBRESOURCE7428995f25c6affba58bea877dbde870)
```
debugfs> logdump -a
Found expected sequence 275805, type 5 (revoke table) at block 12466
    Dumping revoke block, sequence 292155, at block 12466:
    Revoke FS block 1636582
    Found sequence 275805 (not 292155) at block 12467: end of journal.
Found expected sequence 275805, type 1 (descriptor block) at block 12467
    Dumping descriptor block, sequence 292155, at block 12467:
    FS block 1574073 logged at journal block 12468 (flags 0x0)
    FS block 1031 logged at journal block 12469 (flags 0x2)
    FS block 1573639 logged at journal block 12470 (flags 0x2)
    ... ...
    FS block 1574089 logged at journal block 12508 (flags 0x2)
    FS block 1036 logged at journal block 12509 (flags 0xa)
    Found sequence 275805 (not 292155) at block 12510: end of journal.
Found expected sequence 275805, type 2 (commit block) at block 12510
```
#### 块的标准头

所有的块在开头都会包含这个结构，用于标识自己块的类型；

> include/linux/jbd2.h
```
/*
 * Descriptor block types:
 */
#define JBD2_DESCRIPTOR_BLOCK   1   /* 描述块 */
#define JBD2_COMMIT_BLOCK       2   /* 提交块 */
#define JBD2_SUPERBLOCK_V1      3   /* 超级块 */
#define JBD2_SUPERBLOCK_V2      4   /* 超级块 */
#define JBD2_REVOKE_BLOCK       5   /* 撤销块 */
/*
 * Standard header for all descriptor blocks:
 */
typedef struct journal_header_s
{
        __be32          h_magic;     /* #define JBD2_MAGIC_NUMBER 0xc03b3998U */
        __be32          h_blocktype; /* 标识 */
        __be32          h_sequence;  /* 该事务的tid */
} journal_header_t;
```

#### 块的结尾
```
/* Tail of descriptor or revoke block, for checksumming */
struct jbd2_journal_block_tail {
        __be32          t_checksum;     /* crc32c(uuid+descr_block) */
};
```

#### 日志超级块

> include/linux/jbd2.h
```
/*
 * The journal superblock.  All fields are in big-endian byte order.
 */
typedef struct journal_superblock_s
{
/* 0x0000 */
        journal_header_t s_header;

/* 0x000C */
        /* Static information describing the journal */
        __be32  s_blocksize;            /* journal device blocksize */
        __be32  s_maxlen;               /* total blocks in journal file */
        __be32  s_first;                /* 记录日志块的起始逻辑块号 */

/* 0x0018 */
        /* Dynamic information describing the current state of the log */
        __be32  s_sequence;             /* 日志区第一个未重演的事务的id */
        __be32  s_start;                /* 日志区第一个未重演的事务的逻辑块号，如果==0则表示日志空 */

/* 0x0020 */
        /* Error value, as set by jbd2_journal_abort(). */
        __be32  s_errno;

/* 0x0024 */
        /* Remaining fields are only valid in a version-2 superblock */
        __be32  s_feature_compat;       /* compatible feature set */
        __be32  s_feature_incompat;     /* incompatible feature set */
        __be32  s_feature_ro_compat;    /* readonly-compatible feature set */
/* 0x0030 */
        __u8    s_uuid[16];             /* 128-bit uuid for journal */

/* 0x0040 */
        __be32  s_nr_users;             /* Nr of filesystems sharing log */

        __be32  s_dynsuper;             /* Blocknr of dynamic superblock copy*/

/* 0x0048 */
        __be32  s_max_transaction;      /* Limit of journal blocks per trans.*/
        __be32  s_max_trans_data;       /* Limit of data blocks per trans. */

/* 0x0050 */
        __u8    s_checksum_type;        /* checksum type */
        __u8    s_padding2[3];
/* 0x0054 */
        __be32  s_num_fc_blks;          /* Number of fast commit blocks */
/* 0x0058 */
        __u32   s_padding[41];
        __be32  s_checksum;             /* crc32c(superblock) */

/* 0x0100 */
        __u8    s_users[16*48];         /* ids of all fs'es sharing the log */
/* 0x0400 */
} journal_superblock_t;
```

#### 描述块

其中包含了这个事务所管理的元数据的总结，计为tags；

> 伪代码
```
struct description {
    journal_header_t s_header;
    journal_block_tag_t tag[*];
    __u8                    j_uuid[16]; /* 这个事务所属的文件系统的uuid，可有可无，主要根据tag中的t_flags决定 */
    jbd2_journal_block_tail tail;
}
```

> include/linux/jbd2.h
```
/* Definitions for the journal tag flags word: */
#define JBD2_FLAG_ESCAPE                1       /* on-disk block is escaped */
#define JBD2_FLAG_SAME_UUID     2       /* 和上一个tag的uuid一样 */
#define JBD2_FLAG_DELETED       4       /* block deleted by this transaction */
#define JBD2_FLAG_LAST_TAG      8       /* 本个描述块中最后一个tag */

typedef struct journal_block_tag_s
{
        __be32          t_blocknr;      /* 这个元数据记录的是盘上的哪个位置的数据 */
        __be16          t_checksum;     /* truncated crc32c(uuid+seq+block) */
        __be16          t_flags;        /* 如果这个tag记录的uuid和上一个一样，可以将其赋值为JBD2_FLAG_SAME_UUID，并且后面不用跟uuid */
        __be32          t_blocknr_high; /* most-significant high 32bits. */
} journal_block_tag_t;
```

#### 撤销块
> 伪代码
```
struct revoke {
    jbd2_journal_revoke_header_s s_header;
    u64 revoke_block_numb[*];
    jbd2_journal_block_tail tail;
}
```
> include/linux/jbd2.h
```
typedef struct jbd2_journal_revoke_header_s
{
        journal_header_t r_header;
        __be32           r_count;       /* 这个块中一共使用了多少比特 */
} jbd2_journal_revoke_header_t;
```

#### 提交块
> include/linux/jbd2.h  
```
struct commit_header {
        /* 其实说到底提交块的前几个字节还是journal_header_t，只不过是将其分开了而已 */
        __be32          h_magic;
        __be32          h_blocktype;
        __be32          h_sequence;
        unsigned char   h_chksum_type;
        unsigned char   h_chksum_size;
        unsigned char   h_padding[2];
        __be32          h_chksum[JBD2_CHECKSUM_BYTES];
        /* 记录提交时间  */
        __be64          h_commit_sec;
        __be32          h_commit_nsec;
};
```


> 至此根据盘上日志区能看到的东西已分析完，但是它是怎么生成的呢？

> ### **查看日志落盘前，先认识一些概念和结构**

#### 结构
> [link](https://note.youdao.com/s/AdCfc6GM)
##### handle
- 一个handle代表针对文件系统的一次原子操作（内存原子性）。在一个handle中，可能会修改若干个缓冲区，即buffer_head；

##### transcation
- 日志区落盘的基本单位（落盘原子性），将多个handle修改的buffer_head落盘；因为handle保证内存原子，所以可以保证每次对buffer_cache的内存修改到落盘是原子的；
- 状态：
    T_RUNNING,
    T_LOCKED,
    T_SWITCH,
    T_FLUSH,
    T_COMMIT,
    T_COMMIT_DFLUSH,
    T_COMMIT_JFLUSH,
    T_COMMIT_CALLBACK,
    T_FINISHED；
- 每个journal中只有一个running的transcation，用于表示当前正在运行的事务；

##### journal
- 日志管理的总结构；

##### buffer_head
- 内核用来管理磁盘缓冲区的结构，后面统称bh；

##### journal_head
- 当buffer_head进入jbd2管理后会将其和journal_head相互关联,后面统称jh；

#### 概念
##### commit
- 把内存中transaction中的磁盘缓冲区中的数据写到磁盘的日志空间上；

##### checkpoint
- 日志的空间是有限的，而且是环形的，如果一直存会将原来的覆盖掉；因此当元数据被记录到日志的同时，会将自己通过wbi进行落盘，当bh落盘成功将自己置为update，如果一个事务中管理的bh都为update则将这个事务checkpoint掉；

##### revoke
- 如果删除一个的bh在之前已经被进行修改，并记录在日志区中，就会将这个bh加入到对应的hashlist中，而在事务commit和recover的时候，就不会将这个bh进行记录和重演，而是增加一个revoke块；
- 这里涉及的io优化有两点：1）本个事务中已经被revoke的块不需要落盘到日志区；2）日志重演时在这个事务tid之前的事务的元数据不需要进行落盘；

##### recover
- 将日志中保存的元数据进行落盘；
- 详见jbd2_journal_recover函数，但是其实也只是现在bh中；（此处不能看到原子性）
- 等上面bh都落盘，才会去阻塞修改jsb；（保证原子性）

##### kjournald2
- 事务提交的内核进程；
- 外层主要是一个大循环，if(已经提交事务id != 最近请求提交的事务)则调用事务提交函数；
- jbd2_journal_commit_transaction关键事务提交函数；

---
> ###  **日志管理1（加入管理）**
jbd2类似一个模块，如果要将一个bh进行日志管理，是需要加入的；其实本质就是将自己在不同时刻加入到某个transaction的某个链表，而加入之前需要将自己封装成jh，所以bh和jh是一一对应的；下面列举了几个将bh加入jbd管理的函数：
##### jbd2_journal_get_create_access
- 将新的bh加入日志管理；
##### jbd2_journal_get_write_access
- 将可能在历史的某个transaction中的bh加入日志管理；
##### jbd2_journal_get_undo_access
- 将元数据的bh加入日志管理；（好像ext4没有使用的都是jbd2_journal_get_write_access）

可以看到这几个函数的入参都是bh，也就是说在函数中如果没有bh没有对应jh是会去创建一个对应的jh的；
而最新加入到jbd中的jh一定是update的，jbd会将这种jh挂载当前transaction的BJ_Reserved链表上；

##### jbd2_journal_dirty_metadata
- 如果此时对bh进行修改，需要调用jbd2_journal_dirty_metadata将jh置dirty（其实这些标志位还是在bh上，jh使用的bh->flag中没有使用的高位作为自己状态位），并将dirty的jh挂载在transaction的BJ_Metadata量表上；

##### jbd2_journal_revoke
- 如果在一个事务中，前面对一个bh进行修改，但是后面又将bh删除，bh就不需要进行落盘（落盘会降低io性能），可以将它dirty请掉然后加入到revoke_hash_list中；
- 一个journal中有两个revoke_hash_list，当一个transcation commit时，本事务之前revoke的bh保存在其中一个hash_list中，新创建的running transcation使用另一个revoke_hash_list（后备隐藏能源）；

---
> ###  **日志落盘流程**
以上已经将jh放在对应list中，下面讲解事务提交机制；
##### jbd2_journal_start_thread
- 每个块设备挂载之后内核会调用此函数；
- 核心创建一个名为jbd2/devname的内核线程（实质是kjournald2函数），然后加入j_wait_done_commit等待队列，后面在handle需要被sync的时候会将其唤醒；
- 内核线程如下：
```
[root@localhost ~]# ps -ef | grep jbd
root         531       2  0 Oct10 ?        00:00:04 [jbd2/dm-0-8]
root         689       2  0 Oct10 ?        00:00:00 [jbd2/sda1-8]
root         794       2  0 Oct10 ?        00:00:00 [jbd2/dm-2-8]
```

##### kjournald2内核进程
[kjournald2流程图](https://note.youdao.com/s/TfgVHKCW)

##### jbd2_journal_commit_transaction函数
- 1)等待使用这个事务的handle用完; 2)释放没有使用的release_bh; 3)检查checkpointd链表中的事务是否有落盘成功的;
- 1)等待数据块落盘; 2)添加revoke块到log_bufs中;
- 1)循环添加描述块和元数据块到wbuf中;1)每添加一个元数据块就将tag加入到描述块中
- 1)将元数据和描述块下io; 
- 1)等待元数据落盘结束;2)将元数据挂载BJ_Forget链表上等待checkpointd;
- 1)等待revoke块和描述块落盘成功;
- 1)提交块落盘结束一次事务提交;
- 1)检查forgetlist中的bh是否落盘成功，如果没有则事务加入checkpointd链表中等待checkpointd;
- 1)如果checkpointlist中没有jh，则释放本事务;2)跟新journal的state;

---
> ###  **日志管理2（原子性管理）**
##### jbd2_journal_start
- 一次原子操作的开始；
- 获取handle，如果没有则创建；
- 后面的所有操作都将记录在handle对应的tansaction中，而直到jbd2_journal_stop，中间整个过程是不会去提交本个事务的，所以可以保证中间的操作是原子的；
##### jbd2_journal_stop
- 一次原子操作的结束；
- handle->h_sync表示是否将这个handle对应的transaction进行提交，如果需要提交本次事务会在最后进行阻塞等待；

而常见的 ==ext4_journal_start / ext4_journal_stop / ext4_journal_get_write_access / ...== 只不过是将上面的管理函数封装了一下;

---
> ###  **例子**
##### ext4_create
- ext4文件系统在创建文件的时候会去调用这个函数；
![image.png](https://note.youdao.com/yws/res/1135/WEBRESOURCEf1c4da8769e99ee4049905fc493b15e1)

下面浅看一下代码：
```
ext4_new_inode_start_handle
    inode_bitmap_bh = ext4_read_inode_bitmap // 获得inodebitmap的bh
    __ext4_journal_start_sb // 获得一个handle
        jbd2__journal_start
    ext4_journal_get_write_access(inode_bitmap_bh) // bh加入jbd管理
    ext4_set_bit(ino, inode_bitmap_bh->b_data) // 修改bh
    ext4_handle_dirty_metadata(inode_bitmap_bh) // bh置脏
ext4_journal_stop
```



