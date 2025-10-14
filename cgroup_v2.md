blkio子系统可以控制并监控 cgroup 中的进程使用块设备 I/O 相关资源，实现限制I/O操作的次数或带宽和获取I/O操作的统计数据信息。

io接口说明

| 接口          | 说明                                                         | 使用限制                                                     |
| ------------- | ------------------------------------------------------------ | ------------------------------------------------------------ |
| io.max        | 可读可写，设置读写最大值，包括读写字节数和读写IO数，默认为空 | 依赖CONFIG_BLK_DEV_THROTTLING <br /> 1、IO限制包括4种   rbps 读字节   wbps 写字节   riops 读IO   wiops 写IO <br /> 2、带宽与IO数量限制不能太大或太小，太大导致IO限制无明显作用，太小导致IO下发慢，甚至hungtask（2~U64_MAX） |
| io.weight     | 可读可写，设置cgroup的IO下发权重，默认值为100                | 依赖CONFIG_BLK_CGROUP_IOCOST   <br />1、使用前需向父目录的io.cost.qos写入"enable=1"使能对特定设备的限制   echo "8:0 enable=1" > /sys/fs/cgroup/io.cost.qos  <br />2、IO限制包括6种   rbps 读字节   rseqiops 顺序读IO   rrandiops 随机读IO   wbps 写字节   wseqiops 顺序写IO   wrandiops 随机写IO  <br />3、创建子目录前需向父目录的cgroup.subtree_control写入"+io"   echo +io > /sys/fs/cgroup/xxx/cgroup.subtree_control  <br />4、权重设置范围为[1,  10000]   权重设置不能超出合法范围，否则会返回无效参数报错，设置失败  <br />5、只在叶子结点绑定进程   a/b/c/d1   a/b/c/d2   只在d1/d2绑定进程，d1/d2设置io.weight （1~10000） |
| io.stat       | 只读，当前cgroup的io统计<br />rbytes    读大小   <br />wbytes    写大小   <br />rios     读io数量   <br />wios     写io数据   <br />dbytes    discard大小   <br />dios     discard数量  <br />使能latency时会额外增加：  <br /> depth     queue_depth   <br />avg_lat    平均延时   <br />win      avg_lat的统计周期，随io量动态改变 | 依赖CONFIG_BLK_CGROUP                                        |
| io.cost.qos   | 可读可写，设置指定磁盘的QOS(Quality of Service)参数          | 依赖CONFIG_BLK_CGROUP_IOCOST；<br />1、设置参数时需指定磁盘，如设置rpct，需写入echo "8:0 rlat=0" > io.cost.qos，不能写echo "rlat=0" > io.cost.qos <br />2、支持的参数<br />示例：echo "8:0 enable=1 ctrl=user rpct=90.00 rlat=25000 wpct=90.00 wlat=25000 min=100.00 max=10000.00" > blkio.cost.qos<br/>enable: 启用（1）或禁用（0）。默认为0。开启时需设置。<br/>ctrl: 控制模式，可以是 auto 或 user。auto 允许内核自动调整参数，而 user 需要手动配置。默认为auto。当 `ctrl` 配置为 `auto` 时，内核将自动调整与 IO 性能相关的参数，以优化和平衡系统的 IO 行为。<br/>rpct: 读操作的延迟百分位数，范围从 0 到 100。默认为0。<br/>rlat: 读操作的延迟阈值，以微秒为单位。默认为250000/25000/5000(根据内核判断的磁盘类型决定)。<br/>wpct: 写操作的延迟百分位数，范围从 0 到 100。默认为0。<br/>wlat: 写操作的延迟阈值，以微秒为单位。默认为250000/25000/5000。<br/>min: 最小缩放百分比，范围从 1 到 10000。默认为1。建议设100。<br/>max: 最大缩放百分比，范围从 1 到 10000。默认为10000。建议设100。 |
| io.cost.model | 可读可写，设置指定磁盘的设备模型;<br />设备模型参数，通过./iocost_coef_gen.py --testdev /dev/sda获取<br/>脚本位置：tools/cgroup/iocost_coef_gen.py | 依赖CONFIG_BLK_CGROUP_IOCOST；<br />1、设置参数时需指定磁盘<br />2、支持的参数<br />示例: echo "8:0 ctrl=user model=linear rbps=174610612 rseqiops=41788 rrandiops=371 wbps=178587889 wseqiops=42792 wrandiops=379" > blkio.cost.model<br />ctrl: 控制模式，可以是 auto 或 user。<br />
model: 模型类型，目前只支持 linear（线性模型）。<br />[r\|w]bps：读写操作，顺序IO每秒最大字节数。<br />[r\|w]seqiops: 读写操作，顺序IO每秒最大IO数。<br />[r\|w]randiops: 读写操作，随机IO每秒最大IO数。 |
| io.bfq.weight | 可读可写，设置基于bfq的IO下发权重，默认值为100               | 依赖CONFIG_IOSCHED_BFQ；<br />1、需切换bfq调度器<br/>echo bfq > /sys/block/sda/queue/scheduler<br/>2、权重设置范围为[1,1000]，默认为100<br/>3、针对特定磁盘做限制需在该接口写入设备号<br/>echo "8:0 100" > /sys/fs/cgroup/test/io.bfq.weight<br/><br/>其他限制与io.weight相同 |

# 流程

## 对IO进行限制

对设备8:0限制读IO速率为5242880，进程11111对该设备下发的读IO将限制在该速率。命令如下：

```
mkdir -p /sys/fs/cgroup/blkio_test
echo "8:0 enable=0" > /sys/fs/cgroup/io.cost.qos
echo "8:0 rbps=5242880" > /sys/fs/cgroup/blkio_test/io.max
echo 11111 > /sys/fs/cgroup/blkio_test/cgroup.procs
```

## 对不同进程按权重进行IO限制

对访问设备8:0的进程按权重进行IO限制。

对设备8:0限制读IO速率为819200，进程11111和进程22222按100:400的权重比例共享该速率限制。命令如下：

 ```
mkdir -p /sys/fs/cgroup/blkio_test
echo "8:0 enable=1" > /sys/fs/cgroup/io.cost.qos
echo "8:0 rbps=819200 rseqiops=20 rrandiops=20" > /sys/fs/cgroup/io.cost.model
echo +io > /sys/fs/cgroup/blkio_test/cgroup.subtree_control
mkdir -p /sys/fs/cgroup/blkio_test/procA
mkdir -p /sys/fs/cgroup/blkio_test/procB
echo 100 > /sys/fs/cgroup/blkio_test/procA/io.weight
echo 400 > /sys/fs/cgroup/blkio_test/procB/io.weight
echo 11111 > /sys/fs/cgroup/blkio_test/procA/cgroup.procs
echo 22222 > /sys/fs/cgroup/blkio_test/procB/cgroup.procs
 ```

通过bfq按权重进行IO限制

```
echo bfq > /sys/block/sda/queue/scheduler
mkdir -p /sys/fs/cgroup/blkio_test
echo +io > /sys/fs/cgroup/blkio_test/cgroup.subtree_control
mkdir -p /sys/fs/cgroup/blkio_test/procA
mkdir -p /sys/fs/cgroup/blkio_test/procB
echo "8:0 100" > /sys/fs/cgroup/blkio_test/procA/io.bfq.weight
echo "8:0 400" > /sys/fs/cgroup/blkio_test/procB/io.bfq.weight
echo 11111 > /sys/fs/cgroup/blkio_test/procA/cgroup.procs
echo 22222 > /sys/fs/cgroup/blkio_test/procB/cgroup.procs
```



## 查询IO相关统计数据

对设备8:0限制读IO速率，下发IO后查询IO统计。命令如下：

```
mkdir -p /sys/fs/cgroup/blkio_test
echo "8:0 enable=0" > /sys/fs/cgroup/io.cost.qos
echo +io > /sys/fs/cgroup/cgroup.subtree_control
echo "8:0 rbps=5242880" > /sys/fs/cgroup/blkio_test/io.max
echo $$ > /sys/fs/cgroup/blkio_test/cgroup.procs
dd if=/dev/sda of=/dev/null bs=4k count=1024 iflag=direct &
cat /sys/fs/cgroup/blkio_test/io.stat
```

