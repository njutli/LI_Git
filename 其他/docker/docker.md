# cgroup

v1与v2的差别：
1. 统一层级：v2 是 single hierarchy（不再像 v1 那样每个 controller 一棵树）
v1 每个子系统一个挂载点
v2 所有子系统共用一个挂载点

2. controller 需要显式 enable：看 cgroup.controllers，写 cgroup.subtree_control 开启 

3. 接口风格更一致：比如 cpu.max / memory.max / io.max 这种统一命名风格

4. 控制接口更集中（blk-cgroup）
read_bps_device/write_bps_device/read_iops_device/write_iops_device 都整合到 io.max


v1
`systemd.unified_cgroup_hierarchy=0`

v2
`systemd.unified_cgroup_hierarchy=1 cgroup_no_v1=all`

cgroup和cgroup2是怎么决定的？
	用户态根据配置挂载的时候决定的，内核根据文件系统名"cgroup"或"cgroup2"查找对应的 file_system_type (cgroup_fs_type/cgroup2_fs_type)

cgroup和cgroup2在文件系统层面的差别？
	参数不同
	get_tree不同 (cgroup1_fs_context_ops/cgroup_fs_context_ops)

v1
每个子系统一个挂载点
```
[root@localhost ~]# mount | grep cgroup
tmpfs on /sys/fs/cgroup type tmpfs (ro,nosuid,nodev,noexec,size=4096k,nr_inodes=1024,mode=755)
cgroup on /sys/fs/cgroup/systemd type cgroup (rw,nosuid,nodev,noexec,relatime,xattr,release_agent=/usr/lib/systemd/systemd-cgroups-agent,name=systemd)
cgroup on /sys/fs/cgroup/net_cls,net_prio type cgroup (rw,nosuid,nodev,noexec,relatime,net_cls,net_prio)
cgroup on /sys/fs/cgroup/pids type cgroup (rw,nosuid,nodev,noexec,relatime,pids)
cgroup on /sys/fs/cgroup/freezer type cgroup (rw,nosuid,nodev,noexec,relatime,freezer)
cgroup on /sys/fs/cgroup/memory type cgroup (rw,nosuid,nodev,noexec,relatime,memory)
cgroup on /sys/fs/cgroup/perf_event type cgroup (rw,nosuid,nodev,noexec,relatime,perf_event)
cgroup on /sys/fs/cgroup/cpu,cpuacct type cgroup (rw,nosuid,nodev,noexec,relatime,cpu,cpuacct)
cgroup on /sys/fs/cgroup/blkio type cgroup (rw,nosuid,nodev,noexec,relatime,blkio)
cgroup on /sys/fs/cgroup/rdma type cgroup (rw,nosuid,nodev,noexec,relatime,rdma)
cgroup on /sys/fs/cgroup/devices type cgroup (rw,nosuid,nodev,noexec,relatime,devices)
cgroup on /sys/fs/cgroup/cpuset type cgroup (rw,nosuid,nodev,noexec,relatime,cpuset)
cgroup on /sys/fs/cgroup/hugetlb type cgroup (rw,nosuid,nodev,noexec,relatime,hugetlb)
cgroup on /sys/fs/cgroup/files type cgroup (rw,nosuid,nodev,noexec,relatime,files)
[root@localhost ~]# ls /sys/fs/cgroup/
blkio    cpu,cpuacct  files    memory            net_prio    rdma
cpu      cpuset       freezer  net_cls           perf_event  systemd
cpuacct  devices      hugetlb  net_cls,net_prio  pids
[root@localhost ~]#
[root@localhost ~]# ls /sys/fs/cgroup/blkio/
blkio.bfq.io_service_bytes                 blkio.throttle.read_bps_device
blkio.bfq.io_service_bytes_recursive       blkio.throttle.read_iops_device
blkio.bfq.io_serviced                      blkio.throttle.write_bps_device
blkio.bfq.io_serviced_recursive            blkio.throttle.write_iops_device
blkio.cost.model                           cgroup.clone_children
blkio.cost.qos                             cgroup.procs
blkio.reset_stats                          cgroup.sane_behavior
blkio.throttle.io_service_bytes            notify_on_release
blkio.throttle.io_service_bytes_recursive  release_agent
blkio.throttle.io_serviced                 tasks
blkio.throttle.io_serviced_recursive
[root@localhost ~]#
```

v2
所有子系统共用一个挂载点
```
[root@fedora ~]# mount | grep cgroup
cgroup2 on /sys/fs/cgroup type cgroup2 (rw,nosuid,nodev,noexec,relatime,nsdelegate,memory_recursiveprot)
[root@fedora ~]# 
[root@localhost ~]# ls /sys/fs/cgroup/
cgroup.controllers      cpu.stat             memory.pressure
cgroup.max.depth        cpu.stat.local       memory.reclaim
cgroup.max.descendants  dev-hugepages.mount  memory.stat
cgroup.pressure         dev-mqueue.mount     sys-fs-fuse-connections.mount
cgroup.procs            init.scope           sys-kernel-config.mount
cgroup.stat             io.cost.model        sys-kernel-debug.mount
cgroup.subtree_control  io.cost.qos          sys-kernel-tracing.mount
cgroup.threads          io.pressure          system.slice
cpu.pressure            io.stat              user.slice
cpuset.cpus.effective   irq.pressure
cpuset.mems.effective   memory.numa_stat
[root@localhost ~]#
[root@localhost ~]# echo "-io" > /sys/fs/cgroup/cgroup.subtree_control
[root@localhost ~]# cat /sys/fs/cgroup/cgroup.subtree_control
memory pids
[root@localhost ~]# mkdir /sys/fs/cgroup/without_io
[root@localhost ~]# ls /sys/fs/cgroup/without_io/
cgroup.controllers      cpu.stat.local           memory.pressure
cgroup.events           io.pressure              memory.reclaim
cgroup.freeze           irq.pressure             memory.stat
cgroup.kill             memory.current           memory.swap.current
cgroup.max.depth        memory.events            memory.swap.events
cgroup.max.descendants  memory.events.local      memory.swap.high
cgroup.pressure         memory.high              memory.swap.max
cgroup.procs            memory.high_async_ratio  memory.swap.peak
cgroup.stat             memory.low               pids.current
cgroup.subtree_control  memory.max               pids.events
cgroup.threads          memory.min               pids.max
cgroup.type             memory.numa_stat         pids.peak
cpu.pressure            memory.oom.group
cpu.stat                memory.peak
[root@localhost ~]# echo "+io" > /sys/fs/cgroup/cgroup.subtree_control
[root@localhost ~]# cat /sys/fs/cgroup/cgroup.subtree_control
io memory pids
[root@localhost ~]# mkdir /sys/fs/cgroup/with_io
[root@localhost ~]# ls /sys/fs/cgroup/without_io/
cgroup.controllers      io.bfq.weight            memory.oom.group
cgroup.events           io.max                   memory.peak
cgroup.freeze           io.pressure              memory.pressure
cgroup.kill             io.stat                  memory.reclaim
cgroup.max.depth        io.weight                memory.stat
cgroup.max.descendants  irq.pressure             memory.swap.current
cgroup.pressure         memory.current           memory.swap.events
cgroup.procs            memory.events            memory.swap.high
cgroup.stat             memory.events.local      memory.swap.max
cgroup.subtree_control  memory.high              memory.swap.peak
cgroup.threads          memory.high_async_ratio  pids.current
cgroup.type             memory.low               pids.events
cpu.pressure            memory.max               pids.max
cpu.stat                memory.min               pids.peak
cpu.stat.local          memory.numa_stat
[root@localhost ~]#

// 创建根cgroup根目录
cgroup_init
 cgroup_setup_root
  allocate_cgrp_cset_links // 分配 cgrp_cset_link ， 保存在局部链表 tmp_links 中
  cgroup_init_root_id // 初始化 struct cgroup_root cgrp_dfl_root
   idr_alloc_cyclic // 为 cgrp_dfl_root 分配一个 id
  kernfs_create_root // 创建 kernfs_root ， ops 是 cgroup_kf_syscall_ops 或 cgroup1_kf_syscall_ops ，以及对应的 kernfs_node
  css_populate_dir // 给子系统添加文件（针对 root_cgrp 这个特殊的子系统）
  rebind_subsystems // 关联子系统与 cgroup_root
   cgroup_apply_control
    cgroup_apply_control_enable
	 css_create
	  blkcg_css_alloc // ss->css_alloc
	   blkcg = kzalloc
	   pol->cpd_alloc_fn // 遍历处理所有已注册的控制策略

// 创建子cgroup子目录
cgroup_mkdir
 cgroup_apply_control_enable
```

task_struct -- css_set
n:1

cgroup_mkdir
	cgroup 目录下的文件怎么来的

全局 cgroup_subsys[] 数组
`#include <linux/cgroup_subsys.h>`

SUBSYS(io) --> io_cgrp_subsys

# cgroup 疑问
问：
父 cgroup 限制 60% ，子 cgroup 限制 60% ，真实的子 cgroup 限制是 36% ？

答：
**父 cgroup 的 `cpu.max` 限制的是“父这个子树整体最多能吃多少 CPU”。**
**子 cgroup 的 `cpu.max` 限制的是“这个子组最多能吃多少 CPU（但仍然不能突破父组总闸门）”。**
所以：
* 父 60%，子 60% ⇒ 子的有效上限仍然是 **60%**（因为父允许到 60%，子也允许到 60%，取更小还是 60）
* 父 60%，子 30% ⇒ 子有效上限 **30%**
* 父 30%，子 60% ⇒ 子有效上限 **30%**（父更严）

# namespace

chroot
bind mount

容器通过 chroot 将当前进程的根目录切换到指定目录下，容器内要访问外部文件的话就用bind mount将外部目录mount到容器内的目录上

bind mount 不新增dentry，新增 mount

mount 参数 --make-shared
将该挂载点设置为shared状态，创建全局唯一的group id，通过unshare新建的挂载点可以新增到该group中。后续该group中任一mount点下mount与umount事件均会传播
```
// 隔离环境外挂载 sda 到 /mnt/sda
[root@fedora ~]# mount /dev/sda /mnt/sda

// 挂载点设置 unshare
[root@fedora ~]# mount --make-share /mnt/sda
[root@fedora ~]# cat /proc/self/mountinfo | grep sda
553 28 8:0 / /mnt/sda rw,relatime shared:234 - ext4 /dev/sda rw

// 指定 propagation 进入隔离环境
[root@fedora ~]# unshare --mount --propagation shared
[root@fedora ~]# cat /proc/self/mountinfo | grep sda
627 601 8:0 / /mnt/sda rw,relatime shared:234 - ext4 /dev/sda rw

// 隔离环境内改变mount树
[root@fedora ~]# mount /dev/sdb /mnt/sda/testdir/
[root@fedora ~]# cat /proc/self/mountinfo | grep sda
627 601 8:0 / /mnt/sda rw,relatime shared:234 - ext4 /dev/sda rw
751 627 8:16 / /mnt/sda/testdir rw,relatime shared:355 - ext4 /dev/sdb rw
[root@fedora ~]#
[root@fedora ~]#
logout

// 退出隔离环境依然可以看到变化
[root@fedora ~]# cat /proc/self/mountinfo | grep sda
553 28 8:0 / /mnt/sda rw,relatime shared:234 - ext4 /dev/sda rw
752 553 8:16 / /mnt/sda/testdir rw,relatime shared:355 - ext4 /dev/sdb rw
[root@fedora ~]#

mount 参数 --make-slave
从当前peer group中剔除，不传播，只接受master mount事件
```

# docker
## 整体架构
![docker架构](https://www.runoob.com/wp-content/uploads/2016/07/docker-architecture.webp)
- docker client（CLI）：就是你敲 docker ps / run 的那个命令行程序。它不负责创建容器，只负责把请求发给 API。
- docker server（dockerd）：长期运行的守护进程，监听 Docker API（默认是本机 Unix socket），接到请求后再去驱动 containerd/runc 把容器落到内核里。

这两者最常见的部署方式就是同机：
- docker CLI 在 shell 里跑一下就退出；
- dockerd 常驻；

两者通过本机的 /var/run/docker.sock 通信。

> Docker 的 client/server 主要是为了把“用户接口”和“系统级操作权限”分离：CLI 只是发 API，真正需要 root 权限和系统操作的部分在 dockerd（以及后面的 containerd/runc）。它们通常同机部署，通过 Unix socket 通信，也支持远程连接

## 软件组件
`docker CLI  →  dockerd  →  containerd  →  containerd-shim  →  runc  →  Linux kernel`
- dockerd：长期运行的守护进程，负责管理 Docker 对象（镜像、容器、网络、卷…），并监听 API 请求。
- containerd：更底层、更通用的容器运行时守护进程，负责镜像、快照、容器生命周期等“容器核心能力”。Docker 现在就是把很多底层能力下沉给它。
- containerd-shim：夹在 containerd 和 runc 之间的“垫片进程”，每个容器（或每个 sandbox）会有对应 shim；shim 负责调用 runc，并在 runc 退出后继续托管容器的生命周期/stdio/退出码等。
- runc：OCI 运行时，真正去调用内核能力创建容器（namespaces/cgroups/mount/exec）。shim 会用 runc 来 create/start/stop。

```
          你（用户态）
             |
             v
        docker CLI
             |
     REST API (unix socket)
             |
             v
        dockerd  ──────────────── 管理：images/containers/networks/volumes
             |
             v
        containerd ────────────── 管理：images + snapshotters + task lifecycle
             |
             v
     per-container shim (containerd-shim) ── 托管容器进程/stdio/退出码
             |
             v
            runc ─────────────── 生成 namespaces/cgroups/mount/seccomp 并 exec
             |
             v
         Linux kernel ─────────── namespaces/cgroups/VFS/net/LSM…
             |
             v
      真实硬件：CPU/内存/网卡/磁盘
```

# k8s
![master结点](https://pic4.zhimg.com/v2-7fa63b292368c8f21bd4582861a6983d_1440w.jpg)

Master节点包括API Server、Scheduler、Controller manager、etcd。
1. API Server是整个系统的对外接口，供客户端和其它组件调用，相当于“营业厅”。
2. Scheduler负责对集群内部的资源进行调度，相当于“调度室”。
3. Controller manager负责管理控制器，相当于“大总管”。

![node结点](https://picx.zhimg.com/v2-8cb338cd8923fa0e6857f45facc8f00f_1440w.jpg)

Node节点包括Docker、kubelet、kube-proxy、Fluentd、kube-dns（可选），还有就是Pod。
Pod是Kubernetes最基本的操作单元。一个Pod代表着集群中运行的一个进程，它内部封装了一个或多个紧密相关的容器。除了Pod之外，K8S还有一个Service的概念，一个Service可以看作一组提供相同服务的Pod的对外访问接口。
1. Docker，创建容器的。
2. Kubelet，主要负责监视指派到它所在Node上的Pod，包括创建、修改、监控、删除等。
3. Kube-proxy，主要负责为Pod对象提供代理。
4. Fluentd，主要负责日志收集、存储与查询。

# k8s & docker 疑问
## 1) k8s pod 是什么
Pod 是 Kubernetes API 里的一个资源对象（API object），核心是两部分：
- metadata：名字、namespace、labels、annotations…
- spec（PodSpec）：你希望它长什么样（跑哪些容器、镜像、命令、端口、卷、资源限制、探针、安全策略…）
- status：实际运行结果（在哪个节点、IP、各容器状态、事件…）

它的本质更像“数据库里的一行记录 + 期望状态”

## 2) k8s master 端到底做了什么？
你可以把 control plane 想成“写规则 + 做分配”，不直接执行容器。

典型链路（从你 kubectl apply -f pod.yaml 开始）：
1. kubectl → apiserver
你提交 YAML（Pod/Deployment 等）。apiserver 做鉴权/准入（admission），然后把对象写进 etcd（集群状态存储）。
2. 控制器（controller-manager）
如果你创建的是 Deployment，它会创建 ReplicaSet，再创建 Pod（让“期望副本数”成立）。
3. 调度器（scheduler）
调度器看所有“还没绑定节点”的 Pod（spec.nodeName 为空），计算资源/亲和/污点等，选一个 node，最后做一个 bind：把 Pod 的 spec.nodeName=<某个worker> 写回 apiserver。

> 到这一步，master 只做了“把 Pod 这个对象写入并决定它应该去哪台机器”，并没有在节点上执行任何东西。

## 3) k8s worker 是怎么“拿到任务”并执行的？
关键点：worker 不是等 master 推送一个包，而是 kubelet 自己 watch apiserver。

1. kubelet watch apiserver
每个节点上的 kubelet 会持续 watch：
“有哪些 Pod 的 spec.nodeName 是我？”
一旦发现新增/变更，就进入 reconciliation（调谐）循环：把本机状态变成 spec 想要的样子。

2. kubelet 调用容器运行时（CRI）
kubelet 不再直接拼 docker run（早就不是那个架构了）。它通过 CRI（Container Runtime Interface） 的 gRPC 接口调用运行时（常见是 containerd + runc）。

3. 先创建 Pod sandbox，再创建容器
kubelet 先让 runtime 创建 PodSandbox（你可以理解为“Pod 的运行壳”，最重要的是网络命名空间等共享资源）。历史上这经常对应一个 “pause” 容器来持有 netns。
然后再为 Pod 里的每个 container 创建并启动容器。

4. 回写 status
kubelet 把容器状态、IP、探针结果等更新回 apiserver 的 Pod status。

> master 把 Pod 的“期望状态”写入 apiserver/etcd，并由 scheduler 把它绑定到某个 worker；worker 上的 kubelet watch 到这个绑定后，生成运行时配置，通过 CRI 驱动容器运行时创建 sandbox 和容器来跑业务。

## 4) PodSpec 和 “docker run 参数”是什么关系？
PodSpec ≈ 更高层的抽象，它确实覆盖了你在 docker run 里会关心的大多数东西，但表达更“平台化”：
kubelet 会把这些字段转换成 CRI 请求（再由 runtime 转成 OCI 配置），最终才变成你熟悉的那套：namespaces / cgroups / mount / seccomp / capabilities / execve

## 5) Pod 为什么是 Pod，而不是一堆容器？
Pod 的核心价值是：把“一组需要共享某些内核隔离域的进程”打包成一个调度与管理单位。

同一个 Pod 里的容器通常共享：
- 网络命名空间（同 IP、localhost 互通）
- IPC 命名空间（可选）
- 卷（volume）（共享目录）

所以 Pod 更像“一个小型的进程组/服务单元”，而不是“容器的集合”。

## 6) 容器内创建的文件在容器外看不到，那容器退出后文件会丢失吗
问：
```
[root@fedora ~]# ls /mnt/sda
lost+found  testdir
[root@fedora ~]# unshare --mount --fork /bin/bash
[root@fedora ~]# echo 123 > /mnt/sda/file
[root@fedora ~]#
exit
[root@fedora ~]# ls /mnt/sda
file  lost+found  testdir
[root@fedora ~]#
```
> 如上，我通过unshare进入隔离环境，创建的新文件后退出，新文件仍可以看到，这种操作和容器有什么差别，容器在基于新的namesapce写文件的情况下，文件不会直接写入宿主机磁盘吗

答：
> 你用 unshare 只创建了 mount namespace，但没有改变挂载树或 rootfs，所以写的还是同一个挂载点，当然宿主机可见。容器在 mount namespace 基础上还会构造独立 rootfs（常用 overlayfs）并 pivot_root/chroot，使写入落到容器的可写层（宿主机磁盘上的另一个目录）。只有当你把宿主机目录 bind mount 进容器时，容器写入才会直接反映到宿主机那个目录。

## 7) 从内核代码或者数据结构角度讲，unshare后隔离环境内外对目录树的改动互不感知是怎么实现的
> unshare(CLONE_NEWNS) 让进程获得独立的 mnt_namespace，本质是复制一份 struct mount 挂载树结构；mount/umount 修改的是这棵树，因此默认不会影响其他 namespace。它不隔离文件系统内容，内容是否可见取决于两边是否仍引用同一 superblock。要避免 mount 事件传播，需要把 / 设为 rprivate。

## 8) clone() 和 unshare() 什么差别
> clone() 决定“创建新任务时共享还是隔离”；unshare() 决定“当前任务把共享的内核上下文拆成私有副本”。两者都能创建新 namespace，但一个把子进程放进去，一个把自己放进去


UnionFS
	COW 镜像分层

docker 存储机制？







