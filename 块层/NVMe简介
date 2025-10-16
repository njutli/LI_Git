[toc]
# 一、NVME概述

NVMe是一种Host与SSD之间通讯的协议，它在协议栈中隶属高层，制定了Host与SSD之间通讯的命令，以及命令如何执行的

![](D:\4-oskernel\学习小结\block\NVME协议层级.PNG)

**NVMe**属于**应用层**，通过使用**传输层PCIe**的服务实现通信

# 二、NVME命令

NVMe有三种命令（基于spec 2.0），
第一种是**Admin Command**，用以Host管理和控制SSD；
第二种是**I/O Command**，用以Host和SSD之间数据的传输；
第三种是**Fabrics Command**，用于支持基于RDMA（Remote Direct Memory Access，远程直接内存访问）的命令和数据传输(drivers\nvme\host\fabrics.c)![image-20230323113120408](C:\Users\l00503603\AppData\Roaming\Typora\typora-user-images\image-20230323113120408.png)

（spec 1.2中只介绍了Admin Command Set与NVM Command Set）

# 三、NVME组成介绍

## （一）整体组成

![](D:\4-oskernel\学习小结\block\NVME组成.PNG)

1、**SQ**：Submission Queue，提交队列，存放HOST提交的命令
2、**CQ**：Completion Queue，完成队列，存放SSD执行指令的结果
3、**DB**：Doorbell Register，门铃寄存器，HOST映射SSD内部[BAR](#BAR)(Base Address Register)空间，通过[Root Complex](# Root Complex)写门铃寄存器通知SSD取指



## （二）SQ/CQ简介

![](D:\4-oskernel\学习小结\block\SQ-CQ.PNG)

1、**Admin**：一组；SQ/CQ 1:1；深度2~4096(4K)

2、**I/O**：一组或多组；SQ/CQ 1:1或n:1(多SQ支持设置不同优先级)；深度2~65536(64K)

对应的，NVME驱动有多个**blk_mq_ops**，如NVME-PCI就有**nvme_mq_admin_ops**和**nvme_mq_ops**两个ops，分别对应于控制器对应的**admin queue**和各namespace对应的**io queue**

[NVME相关流程](#七、NVME相关流程)

![](D:\4-oskernel\学习小结\block\队列1.PNG)

Head/Tail分别指向待插入与待取出的位置

# 四、NVME命令执行流程 

![](D:\4-oskernel\学习小结\block\NVME-IO命令处理流程.PNG)



1、Host写命令到SQ
2、Host写DB，通知SSD取指
3、SSD收到通知，于是从SQ中取指
4、SSD执行指令
5、指令执行完成，SSD往CQ中写指令执行结果
6、然后SSD发短信通知Host指令完成（触发中断）
7、收到短信，Host处理CQ，查看指令完成状态（中断处理）
8、Host处理完CQ中的指令执行结果，通过DB回复SSD

## （一）执行示例

### 1、初始状态

Head = Tail =0（**SQ/CQ空**）

![](D:\4-oskernel\学习小结\block\队列11.PNG)

### 2、Host提交请求

(1) Host向SQ中写入三个命令，Tail_SQ = 3（**3个命令待取**）
(2) Host更新DB，通知SSD有命令待执行，Tail_SQ_DB = 3（**SSD需要取3个命令**）

![](D:\4-oskernel\学习小结\block\队列12.PNG)

### 3、SSD消费请求

(1) SSD从SQ中读取三个命令，Head_SQ = Tail_SQ = 3（**SQ中的3个命令已被取，SQ为空**）
(2) SSD读取三个命令，Head_SQ_DB = Tail_SQ_DB = 3（**SSD已经把需要取的3个命令都取到了本地**）

![](D:\4-oskernel\学习小结\block\队列13.PNG)

### 4、SSD完成请求

(1) SSD完成了两个命令，Tail_CQ_DB = 2（**SSD完成了两个命令**）
(2) SSD反馈命令执行结果，Tail_CQ = 2（**有两个命令的执行结果待Host处理**）
(3) 中断通知Host

![](D:\4-oskernel\学习小结\block\队列14.PNG)

### 5、Host处理命令执行结果

(1) Host取出命令执行结果，Head_CQ = Tail_CQ = 2（**Host已取走命令完成结果，CQ空**）
(2) Host写DB寄存器，通知SSD命令执行结果已处理，Head_CQ_DB = Tail_CQ_DB = 2（**Host已处理命令完成结果**）

![](D:\4-oskernel\学习小结\block\队列15.PNG)

### 疑问

针对SQ，Host只更新Tail_SQ，Head_SQ由SSD更新，那么[Host怎么知道Head_SQ？](#命令执行结果)

针对CQ，Host只更新Head_CQ，Tail_CQ由SSD更新，那么[Host怎么知道Tail_CQ？](#CQ结构)

SSD怎么确认Host读写命令对应的[内存位置](#（二）PRP/SGL)





## （二）PRP/SGL

### 1、PRP (Physical Region Page) -- Admin/IO

![](D:\4-oskernel\学习小结\block\PRP-Entry_Layout.PNG)

PRP Entry本质就是一个64位内存物理地址，只不过把这个物理地址分成两部分：页起始地址和页内偏移。最后两bit是0，说明PRP表示的物理地址只能四字节对齐访问。页内偏移可以是0，也可以是个非零的值。

![](D:\4-oskernel\学习小结\block\PRP-List_conti.PNG)

![](D:\4-oskernel\学习小结\block\PRP-List_noconti.PNG)

PRP Entry描述的是一段连续的物理内存的起始地址。如果需要描述若干个不连续的物理内存就需要若干个PRP Entry。把若干个PRP Entry链接起来，就成了PRP List。

![](D:\4-oskernel\学习小结\block\命令格式PRP-SGL.PNG)

Host下发的命令中带有PRP地址，总共16个字节，每8个字节(64 bit)表示一个PRP Entry

![](D:\4-oskernel\学习小结\block\command中PRP-List解析.PNG)

Command中的PRP可能执行实际的物理地址，也可能指向PRP List



### 2、SGL (Scatter/Gather List) -- IO

SGL是一个数据结构，用以描述一段数据空间，这个空间可以是数据源所在的空间，也可以是数据目标空间。SGL(Scatter Gather List)首先是个List，是个链表，由一个或者多个SGL Segment组成，而每个SGL Segment又由一个或者多个SGL Descriptor组成。SGL Descriptor是SGL最基本的单元，它描述了一段连续的物理内存空间：起始地址+空间大小。

![](D:\4-oskernel\学习小结\block\SGL-List.PNG)

#### (1) SGL Segment

![](D:\4-oskernel\学习小结\block\SGLSegment.PNG)

SGL Segment是一段连续的地址，大小为16字节的整数倍，包含若干个SGL Descriptor

#### (2) SGL Descriptor通用格式

![](D:\4-oskernel\学习小结\block\SGL Descriptor通用格式.PNG)

每个SGL Descriptor大小是16字节。一块内存空间，可以用来放用户数据，也可以用来放**SGL Segment descriptor**。其中Byte 15是descriptor类型，Byte 0~14是各类型descriptor自定义的结构。

这里的**SGL Segment descriptor**与上文的**SGL Segment**不是一个概念，SGL Segment是一组descriptor的集合，SGL Segment descriptor是一种descriptor类型。

#### (3) SGL Data Block descriptor

![](D:\4-oskernel\学习小结\block\SGL-Data-Block-descriptor1.PNG)

![](D:\4-oskernel\学习小结\block\SGL-Data-Block-descriptor2.PNG)

描述目标内存的地址与长度

#### (4) SGL Bit Bucket descriptor

![](D:\4-oskernel\学习小结\block\SGL-Bit-Bucket-descriptor.PNG)

描述需要忽略的内存地址与长度

#### (5) SGL Segment descriptor

![](D:\4-oskernel\学习小结\block\SGL-Segment-descriptor.PNG)

描述下一个SGL Segment的地址与长度

#### (6) SGL Last Segment descriptor

![](D:\4-oskernel\学习小结\block\SGL-Last-Segment-descriptor.PNG)

描述下一个SGL Segment的地址与长度，下一个Segment是最后一个

#### (7) 示例

![](D:\4-oskernel\学习小结\block\SGL读数据示例.PNG)

上图显示了使用 SGL 的数据读取请求示例。在示例中，逻辑块大小为 512B。访问的逻辑块总长度为 13 KiB，其中只有 11 KiB 传输到主机。命令中的逻辑块数 (NLB) 字段应指定为 26，表示控制器上访问的逻辑块的总长度为 13 KiB。共有三个 SGL 段描述逻辑块数据在内存中传输的位置。
三个SGL段一共包含三个Data Block描述符，长度分别为3KiB、4KiB、4KiB。目标 SGL 的段 1 包含一个长度为 2 KiB 的位桶描述符，指定不传输（即忽略）来自 NVM 的 2 KiB 逻辑块数据。目标 SGL 的段 1 还包含一个 Last Segment 描述符，指定该描述符指向的段是最后一个 SGL 段。

### 3、区别

![](D:\4-oskernel\学习小结\block\PRP-SGL区别.PNG)

一段数据空间，对PRP来说，它只能映射到一个个物理页，而对SGL来说，它可以映射到任意大小的连续物理空间



# 五、命令格式

## （一）第一个双字

![](D:\4-oskernel\学习小结\block\command双字0.PNG)



```c
// 通用命令格式
struct nvme_common_command {
	__u8			opcode;
	__u8			flags;
	__u16			command_id;
	__le32			nsid;
	__le32			cdw2[2];
	__le64			metadata;
	union nvme_data_ptr	dptr;
	__le32			cdw10;
	__le32			cdw11;
	__le32			cdw12;
	__le32			cdw13;
	__le32			cdw14;
	__le32			cdw15;
};
```


## （二）Admin Command

![](D:\4-oskernel\学习小结\block\CommandFormat-admin.PNG)

以 **Create I/O Completion Queue command** 为例，使用了 **PRP Entry 1/Command Dword 10/Command Dword 11** 

![](D:\4-oskernel\学习小结\block\CreateCQ-prp.PNG)

![](D:\4-oskernel\学习小结\block\CreateCQ-Dword10.PNG)

![](D:\4-oskernel\学习小结\block\CreateCQ-Dword11.PNG)

**命令提交**

```c
// 提交分配cq命令(创建SSD侧的CQ)
struct nvme_create_cq {
	__u8			opcode;
	__u8			flags;
	__u16			command_id;
	__u32			rsvd1[5];
	__le64			prp1;
	__u64			rsvd8;
	__le16			cqid;
	__le16			qsize;
	__le16			cq_flags;
	__le16			irq_vector;
	__u32			rsvd12[4];
};
// 填充当前命令特有的字段
static int adapter_alloc_cq(struct nvme_dev *dev, u16 qid,
		struct nvme_queue *nvmeq, s16 vector)
{
	struct nvme_command c;
......
	memset(&c, 0, sizeof(c));
	c.create_cq.opcode = nvme_admin_create_cq; // 设置 opcode
	c.create_cq.prp1 = cpu_to_le64(nvmeq->cq_dma_addr);
	c.create_cq.cqid = cpu_to_le16(qid);
	c.create_cq.qsize = cpu_to_le16(nvmeq->q_depth - 1);
	c.create_cq.cq_flags = cpu_to_le16(flags);
	c.create_cq.irq_vector = cpu_to_le16(vector);

	return nvme_submit_sync_cmd(dev->ctrl.admin_q, &c, NULL, 0);
}

// 填充命令公共部分
nvme_queue_rq
 nvme_setup_cmd
  nvme_setup_passthrough

static void nvme_submit_cmd(struct nvme_queue *nvmeq, struct nvme_command *cmd,
			    bool write_sq)
{
	spin_lock(&nvmeq->sq_lock);
	memcpy(nvmeq->sq_cmds + (nvmeq->sq_tail << nvmeq->sqes),
	       cmd, sizeof(*cmd)); // 命令写入SQ
	if (++nvmeq->sq_tail == nvmeq->q_depth)
		nvmeq->sq_tail = 0;
	nvme_write_sq_db(nvmeq, write_sq); // 写DB通知SSD
	spin_unlock(&nvmeq->sq_lock);
}
```

**结果处理**

![](D:\4-oskernel\学习小结\block\CompletionQueueEntry.PNG)

```c
// .complete	= nvme_pci_complete_rq
void nvme_complete_rq(struct request *req)
{
	trace_nvme_complete_rq(req);
	nvme_cleanup_cmd(req);

	if (nvme_req(req)->ctrl->kas)
		nvme_req(req)->ctrl->comp_seen = true;

	switch (nvme_decide_disposition(req)) {
	case COMPLETE:
		nvme_end_req(req);
		return;
	case RETRY:
		nvme_retry_req(req);
		return;
	case FAILOVER:
		nvme_failover_req(req);
		return;
	}
}
```



## （三）NVM Command

![](D:\4-oskernel\学习小结\block\CommandFormat-NVM.PNG)

![](D:\4-oskernel\学习小结\block\CommandFormat-NVM2.PNG)

以 **Read command** 为例

![](D:\4-oskernel\学习小结\block\ReadCommand1.PNG)

![](D:\4-oskernel\学习小结\block\ReadCommand2.PNG)

![](D:\4-oskernel\学习小结\block\ReadCommand3.PNG)

![](D:\4-oskernel\学习小结\block\ReadCommand4.PNG)

**命令提交**

```c
struct nvme_rw_command {
	__u8			opcode;
	__u8			flags;
	__u16			command_id;
	__le32			nsid;
	__u64			rsvd2;
	__le64			metadata;
	union nvme_data_ptr	dptr;
	__le64			slba;
	__le16			length;
	__le16			control;
	__le32			dsmgmt;
	__le32			reftag;
	__le16			apptag;
	__le16			appmask;
};

nvme_queue_rq
 nvme_setup_cmd
   // req->cmd_flags -- REQ_OP_READ
   nvme_setup_rw // nvme_cmd_read
   // 读写IO从通用块层下发过来，不会预先设置NVME的opcode，需要根据request中的flag设置nvme_command的opcode
    
```



# 六、其他

## （一）Controller

NVMe (Non-Volatile Memory Express) Controller 是一种特殊的控制器，用于连接 PCIE 总线和 NVMe 存储设备。它是硬件级别的设备，负责管理 NVMe 存储设备与主机之间的数据传输和命令控制。

每个NVMe Controller都被视为一个独立的设备，在PCIe总线上具有独特的设备ID、地址和功能。每个Controller都可以与系统的CPU和内存交互，并通过PCIe总线进行数据传输。多个NVMe Controllers可以并行操作，从而提高系统的整体性能。

## （二）Namespace

命名空间是一定数量的格式化的非易失性内存，可以被主机直接访问。名称空间 ID (NSID) 是控制器用来提供对名称空间的访问的标识符。

一个SSD的存储空间可以分割成多个namespace，每个namespace都有从0开始的一段逻辑地址。

![](D:\4-oskernel\学习小结\block\两个NS.PNG)

Host发送IO指令时会通过NSID指定namespace（namespace信息由host发送**nvme_admin_identify**命令从SSD获取）

Host可以通过Namespace Management command创建或删除namespace（**nvme_admin_ns_mgmt** —— 未见使用）

![](D:\4-oskernel\学习小结\block\CommonCommandFormat.PNG)

对每个NS来说，都有一个4KB大小的数据结构来描述它。该数据结构描述了该NS的大小，整个空间已经写了多少，每个LBA的大小，以及端到端数据保护相关设置，该NS是否属于某个Controller还是几个Controller可以共享，等等。
NS由Host创建和管理，每个创建好的NS，从Host操作系统角度看来，就是一个独立的磁盘，用户可在每个NS做分区等操作。

![](D:\4-oskernel\学习小结\block\NS结构.PNG)

## （三）SR-IOV

![](D:\4-oskernel\学习小结\block\SR-IOV.PNG)

Single Root- I/O Virtualization，SR-IOV技术允许在虚拟机之间高效共享PCIe设备，并且它是在硬件中实现的，可以获得能够与本机性能媲美的I/O 性能。单个I/O 资源（单个SSD）可由许多虚拟机共享。共享的设备将提供专用的资源，并且还使用共享的通用资源。这样，每个虚拟机都可访问唯一的资源。

该SSD作为PCIe的一个Endpoint，实现了一个物理功能 (Physical Function ,PF)，有4个虚拟功能（Virtual Function，VF）关联该PF。每个VF，都有自己独享的NS，还有公共的NS （NS E)。此功能使得虚拟功能可以共享物理设备，并在没有 CPU 和虚拟机管理程序软件开销的情况下执行 I/O。

## （四）多Controller

### 1、单总线多Controller

![](D:\4-oskernel\学习小结\block\单总线多Controller.PNG)

### 2、多总线多Controller

![](D:\4-oskernel\学习小结\block\多总线多Controller.PNG)

### 3、多主机多Controller

![](D:\4-oskernel\学习小结\block\多主机多Controller.PNG)

# 七、NVME相关流程

```c
// 驱动初始化
nvme_init
 pci_register_driver
  __pci_register_driver
   driver_register
    driver_find // 查找是否已注册
    bus_add_driver // 将驱动添加至总线
    driver_add_groups // 创建sysfs接口

// 设备探测
nvme_probe
 nvme_dev_map // 映射bar空间
  nvme_remap_bar // dev->bar
 nvme_init_ctrl
 nvme_reset_ctrl
  // queue_work reset work
  nvme_reset_work
    nvme_pci_configure_admin_queue // 设置admin queue
     nvme_alloc_queue // 分配admin queue -- nvme_queue
      dma_alloc_coherent // host分配 cqes
      nvme_alloc_sq_cmds // host分配 sq_cmds
     nvme_init_queue // 初始化admin queue，dev->online_queues++
     lo_hi_writeq // 写bar寄存器，将host侧的SQ/CQ地址传递给SSD
     queue_request_irq // 注册admin queue中断回调 nvme_irq
    nvme_alloc_admin_tags // 分配admin tags
     blk_mq_alloc_tag_set // 分配dev->admin_tagset
     // dev->ctrl.admin_q = blk_mq_init_queue(&dev->admin_tagset);
     blk_mq_init_queue // 分配dev->ctrl.admin_q -- request_queue -- nvme有两个blk_mq_ops，此处为 nvme_mq_admin_ops
    nvme_setup_io_queues // 设置io queue
     nvme_create_io_queues
      nvme_alloc_queue // 分配io queue -- nvme_queue
       dma_alloc_coherent // host分配 cqes
       nvme_alloc_sq_cmds // host分配 sq_cmds
      nvme_create_queue
       adapter_alloc_cq // 通知SSD分配cq
        nvme_submit_sync_cmd // 命令提交
         __nvme_submit_sync_cmd
          nvme_alloc_request
          blk_execute_rq
            nvme_queue_rq // queue_rq()
             nvme_submit_cmd // 将cmd拷贝到sq_cmds并写DB
       adapter_alloc_sq // 通知SSD分配sq
       nvme_init_queue // 初始化queue
       queue_request_irq // 注册IO queue中断回调 nvme_irq
    nvme_dev_add // 分配io tags
     blk_mq_alloc_tag_set // 分配dev->tagset
    nvme_start_ctrl
     nvme_queue_scan
     // queue_work scan work
     nvme_scan_work
      nvme_scan_ns_list
       nvme_submit_sync_cmd // 提交Identify command，获取nsid信息
       nvme_validate_or_alloc_ns // 使能或分配ns
        nvme_find_get_ns // 如果当前ns已在ctrl->namespaces链表中，则使能
        nvme_validate_ns
        nvme_alloc_ns // 否则分配新的ns插入链表
         blk_mq_init_queue // 分配ns->queue -- request_queue -- nvme有两个blk_mq_ops，此处为 nvme_mq_ops
         alloc_disk_node // 分配disk
         // disk->queue = ns->queue; 此处每一个disk对应一个ns，最终体现为host可见的一个物理设备
         nvme_ns_add_to_ctrl_list // 插入链表
         device_add_disk // 每个ns对应一个设备，add disk
 nvme_async_probe // 等待reset work与scan work结束
```



# 八、附加说明

### BAR

![](D:\4-oskernel\学习小结\block\PCIe配置空间.PNG)



每个PCIe设备，有这么一段空间，Host软件可以读取它获得该设备的一些信息，也可以通过它来配置该设备，这段空间就叫做PCIe的配置空间

整个配置空间就是一系列寄存器的集合，其中Type 0是Endpoint的配置，Type 1是Bridge（PCIe时代就是Switch）的配置，都由两部分组成：64 Bytes的Header+192Bytes的Capability结构



![](D:\4-oskernel\学习小结\block\SSD-BAR定义.PNG)

**SSD内部BAR寄存器定义**

![](D:\4-oskernel\学习小结\block\SSD-BAR定义-code.PNG)

**内核中NVME寄存器定义**(include\linux\nvme.h)

### Root Complex

如果CPU想读PCIe外设的数据，先叫RC通过TLP把数据从PCIe外设读到Host内存，然后CPU从Host内存读数据；如果CPU要往外设写数据，则先把数据在内存中准备好，然后叫RC通过TLP写入到PCIe设备

![](D:\4-oskernel\学习小结\block\RootComplex.PNG)

最左边虚线的表示CPU要读Endpoint A的数据，RC则通过TLP（经历Switch）数据交互获得数据，并把它写入到系统内存中，然后CPU从内存中读取数据（紫色箭头所示），从而CPU间接完成对PCIe设备数据的读取



### 命令执行结果

![](D:\4-oskernel\学习小结\block\命令执行结果.PNG)

Host可以根据SSD反馈的命令执行结果得到Head_SQ指向的位置



### CQ结构

![image-20230323162601055](C:\Users\l00503603\AppData\Roaming\Typora\typora-user-images\image-20230323162601055.png)

CQ中每条命令完成条目中的"P" bit初始化为0，SSD在往CQ中写入命令完成条目时，会把"P"写成1，Host通过检查每个条目的"P"确认Tail_CQ位置




# 参考链接：

http://www.ssdfans.com/?p=8137

http://www.ssdfans.com/?p=8210

https://vatiminxuyu.gitbooks.io/xuyu/content/blog/nvme-what.html

https://nvmexpress.org/wp-content/uploads/NVM-Express-Base-Specification-2.0c-2022.10.04-Ratified.pdf

NVM_Express_1_2_Gold_20141209



NVME multipath？

request_queue  nvme_queue  hctx disk关系？
