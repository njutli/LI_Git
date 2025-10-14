### blk-cgroup 框架

#### 组织关系

##### blkcg

blkio子系统默认挂载位置：

```
cgroup on /sys/fs/cgroup/blkio type cgroup (rw,nosuid,nodev,noexec,relatime,blkio)
```

blkcg表示cgroup中blkio子系统中的一个control group，体现给用户是一个目录

<img width="525" height="242" alt="image" src="https://github.com/user-attachments/assets/b2093288-bdbb-40b6-80cc-d91a9d423aaa" />

##### blkg(struct blkcg_gq)

用来关联blkcg和request_queue(被控制的设备)

同一个request_queue在同一个blkcg下最多只有一个blkg

<img width="734" height="298" alt="image" src="https://github.com/user-attachments/assets/df994484-f9ea-4086-ab83-bf9dab0303c7" />

bio初始化时，会根据**设备 + 进程**关联一个唯一的blkg，实现在bio_associate_blkg()

##### blkcg_policy

当前内核在blkio子系统中一共支持5种控制策略：

- blk-throttle：基于io 带宽/iops 的绝对管控
- bfq：基于io带宽的相对管控
- iocost：基于cost的相对管控
- iolatency：基于io延时
- ioprio：基于io优先级

每种控制策略需要实现 blkcg_policy：

```c
struct blkcg_policy {
        int                             plid;
        /* cgroup files for the policy */
        struct cftype                   *dfl_cftypes;
        struct cftype                   *legacy_cftypes;

        /* operations */
        blkcg_pol_alloc_cpd_fn          *cpd_alloc_fn;
        blkcg_pol_init_cpd_fn           *cpd_init_fn;
        blkcg_pol_free_cpd_fn           *cpd_free_fn;
        blkcg_pol_bind_cpd_fn           *cpd_bind_fn;

        blkcg_pol_alloc_pd_fn           *pd_alloc_fn;
        blkcg_pol_init_pd_fn            *pd_init_fn;
        blkcg_pol_online_pd_fn          *pd_online_fn;
        blkcg_pol_offline_pd_fn         *pd_offline_fn;
        blkcg_pol_free_pd_fn            *pd_free_fn;
        blkcg_pol_reset_pd_stats_fn     *pd_reset_stats_fn;
        blkcg_pol_stat_pd_fn            *pd_stat_fn;
};
```

其中cftype定义会在cgroup下目录下的文件一节读写操作的时间

cpd相关ops用来维护该policy在blkcg级别相关数据结构的使用，当前只有bfq和iocost用来维护cgroup下默认的配置

pd相关ops用来维护该policy在blkg级别相关数据接口的使用，例如在某个设备下的配置

> pd的ops中，很多策略并没有遵守 alloc/init/online 这些接口该做的事情

#### 相关流程

##### 注册控制策略

当前不支持以模块的形式，只在系统初始化注册

blkcg_policy_register

将定义的policy记录到一个全局数组中：

```c
static struct blkcg_policy *blkcg_policy[BLKCG_MAX_POLS];
```

##### 使能控制策略

```c
int blkcg_activate_policy(struct request_queue *q, const struct blkcg_policy *pol)
```

request_queue中有一个bitmap，索引是policy的id，来记录某个policy是否使能

```c
struct request_queue {
...
#ifdef CONFIG_BLK_CGROUP
	DECLARE_BITMAP          (blkcg_pols, BLKCG_MAX_POLS);
	struct blkcg_gq         *root_blkg;
	struct list_head        blkg_list;
#endif
...
}
```

依次遍历request_queue中的blkg，依次调用pd_alloc_fn, pd_init_fn和pd_online_fn

已经使能的策略会记录到blkg中

```c
blkcg_activate_policy
 list_for_each_entry_reverse(blkg, &q->blkg_list, q_node)
   pd = pol->pd_alloc_fn
   pol->pd_init_fn
   pol->pd_online_fn
   blkg->pd[pol->plid] = pd
```

##### 禁用控制策略

```c
void blkcg_deactivate_policy(struct request_queue *q, const struct blkcg_policy *pol)
```

与使能的操作相反

依次遍历request_queue中的blkg，依次调用pd_offline_fn和pd_free_fn，并且清除blkg中记录的策略

blkg仍然在request_queue中

**blkg创建**

触发点是第一次下io

##### blkg删除

触发点有两个：cgroup删除和删盘
