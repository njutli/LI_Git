[Linux 通用块层之IO合并](https://mp.weixin.qq.com/s?__biz=Mzg2OTc0ODAzMw==&mid=2247502091&idx=1&sn=68b81ad43c3e54f03771d7fb05069444&source=41&poc_token=HBTIJ2mj_oXQ54vboFJqkhWQ7QRxRS2EaWdTgfVf)

[文件读写（BIO）波澜壮阔的一生](https://mp.weixin.qq.com/s?__biz=Mzg2OTc0ODAzMw==&mid=2247502042&idx=1&sn=6bb3a9ba76fb40221c719412b5bddc17&source=41&poc_token=HH7JJ2mjw5wuch-TIMLeCOAs1Bt1ZL7XqhUbLBFg)

[Linux 通用块层之DeadLine IO调度器](https://mp.weixin.qq.com/s?__biz=Mzg2OTc0ODAzMw==&mid=2247502041&idx=1&sn=052953ba0c5312120a81814d57b4b77b&source=41&poc_token=HHvRQGmjkcJFf4pCdcDbbWj_MbVu8XQmot3RoWLy)

# plug unplug
蓄流的主要目的就是为了增加请求合并的机会，特别是脏页回刷的场景(fsync_buffer_list)

1. __submit_bio 中当前进程下的IO不是只有一个吗，为什么要plug
当前进程对dm设备下发一个大IO，在dm层可能会依据map拆分成不同设备上的小IO，这些IO都会被submit。在dm设备堆叠的场景下，可能会出现用户态下发的一个大IO，拆分成很多小IO，而这些小IO可能是发往同一个设备，可以合并的IO，不应该对每个IO都生成request直接下发，而是通过plug暂存，再通过request级别的合并来减少向底层设备发送IO的次数，以提高效率

```
// 向dm设备下发IO
submit_bio
 submit_bio_noacct
  submit_bio_noacct_nocheck
   __submit_bio_noacct
    __submit_bio
	 dm_submit_bio // disk->fops->submit_bio
	  dm_split_and_process_bio
	   __split_and_process_bio
	   dm_table_find_target // 根据当前bio的起始位置ci->sector获取对应的 dm_target
	   alloc_tio // 分配针对当前 dm_target 的 bio
	   __map_bio // 映射到具体下层设备
	   ci->sector_count -= len // 减去当前 dm_target 对应的IO大小
	   // 1. 如果减去已映射的IO大小，用户态下发的IO剩余大小为0，则结束
	   // 2. 如果减去已映射的IO大小，用户态下发的IO剩余大小非0，则需要处理剩下的IO
	   bio_trim //重新设置IO大小为 ci.sector_count
	   submit_bio_noacct // 再次提交IO
	   // 至此用户态下发的IO，就已经被拆分成一个已明确下层设备的IO，和剩下的一个没明确下层设备的IO
	   // 剩下的这个IO会继续递归处理
```

2. 对于没有设备堆叠的情况，plug有用吗
针对脏页回刷场景，遍历脏页链表下发IO，这些脏页生成的IO可能是下发到同一设备上的，可通过plug合并，fsync_buffer_list
aio场景，用户下发的IO数超过 AIO_PLUG_THRESHOLD 时，这些IO会被plug

```
// fs/aio.c io_submit
blk_start_plug
io_submit_one // 遍历用户提交的 iocb
blk_finish_plug
```


# IO合并
## plug 合并

> 第一个bio会生成request插入plug list，后续的bio会和plug list里的reqeust比较，看是否能合并，可以的话则合并，否则生成新的request

1. 后向合并
bio的尾等于req的头
```
ELEVATOR_BACK_MERGE
blk_rq_pos(rq) + blk_rq_sectors(rq) == bio->bi_iter.bi_sector
```

2. 前向合并
bio的头等于req的尾
```
ELEVATOR_FRONT_MERGE
blk_rq_pos(rq) - bio_sectors(bio) == bio->bi_iter.bi_sector
```

## 调度器合并
__blk_mq_sched_bio_merge
 1) e->type->ops.bio_merge
    往调度器内部已有的 request 上合并
 2) blk_bio_list_merge
    往软件队列上已有的 request 上合并


# deadline 调度器
deadline调度器的工作过程就是： 通用块层通过deadline注册的操作接口将IO请求提交给deadline，deadline在收到请求之后根据请求的扇区编号将请求在sort_list中排好序，同时给每个请求添加超时时间厝，并插入到fifo_list尾部。一个IO请求同时挂接在sort_list和fifo_list中。通用块层需要派发IO请求（泻流或者direct IO）的时候也是调用deadline注册的电梯派发接口，deadline在派发一个IO请求时会基于电梯算法综合考虑IO请求是否超时、写饥饿是否触发、是否满足批量条件来决定到底派发哪个IO请求，派发之后会将该IO请求同时从sort_list和fifo_list中删除。

## bio通过deadline_add_request接口添加到调度队列
首先判断request的IO方向，根据IO方向通过deadline_add_rq_rb将request添加到读/写红黑树中，request在红黑树中以请求的起始扇区作为节点的key value，可以直接认为红黑树中的request是按扇区增加的方向排好了序。然后给request添加一个超时时间厝，根据IO方向添加到先进先出链表尾部，fifo_list中同一IO方向上的request具有相同的fifo_expire，所以先添加进来的request先超时，每次都是从尾部添加，所以头部的request先超时



dcache icache

IO合并点
IO合并要求
读IO不会被plug?




current->bio_list
bio级别的蓄流？

plug 和 bio_list 分别对应 request-base 和 bio-base 的dm设备？


软件队列与硬件队列，多队列
nvme namespace

bio->bi_next


