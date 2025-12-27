下面按你喜欢的“**用户态做了什么 → 通过什么系统调用/内核机制 → 最终效果**”的思路，把 Docker 从 `docker run` 到容器运行时的日常操作（读写文件、网络、限制资源、exec/stop）串成一条“可调试”的路径。

---

## 0) 总体架构：从 `docker` 命令一路走到内核

先把角色钉死（面试经常先问这个）：

```
docker CLI  →  dockerd (Docker Engine)  →  containerd  →  containerd-shim  →  runc  →  Linux kernel
```

* `docker` CLI 把命令通过 REST API（通常是 Unix socket `/var/run/docker.sock`）发给 `dockerd`。
* `dockerd` 负责镜像、网络、卷、权限策略等“产品级编排”，并通过 `containerd` 调 OCI runtime（默认 `runc`）去触碰内核的 namespaces / cgroups / SELinux 等。 ([Docker Documentation][1])
* `containerd` 的模型里：**container 是元数据对象，task 才是活着的进程**（这点解释 `docker ps` vs `ps` 很好用）。 ([GitHub][2])
* `runc` 按 OCI 配置把“容器”落到内核：创建 namespace、写 cgroup、挂载 rootfs、设置 capabilities/seccomp，然后 `execve()` 跑你的进程。 ([GitHub][3])

---

## 1) `docker pull`：用户态做了什么？内核做了什么？

### 用户态做了什么

* `dockerd/containerd` 从镜像仓库拉 manifest + layer（tar.gz）并解包。
* 把镜像内容存到本地镜像存储；新版本 Docker（29+ 新安装默认）会用 **containerd image store + snapshotter**（不再完全依赖传统 graphdriver 概念）。 ([Docker Documentation][4])

### 内核/系统调用层面发生了什么

主要就是普通文件 IO：

* `openat()/read()/write()/fsync()`：写入 layer 内容、元数据
* `mkdir()/rename()`：原子替换与提交
* 可能涉及 `mount()`：如果启用了 overlay snapshotter/overlay2，会准备 overlay 的目录结构（lower/upper/work）

### 最终效果

* 镜像在本机变成“**一堆只读层（lowerdir）** + 元数据索引”，为后续容器 rootfs 叠起来做准备。

---

## 2) `docker run`：从命令到一个“隔离的进程”

用一句话总结：**容器不是 VM，它就是一组受限并被隔离的进程**；隔离靠 namespaces，资源靠 cgroups，文件视图靠 rootfs（overlayfs/绑定挂载），安全靠 capabilities/seccomp/LSM。([Docker Documentation][1])

下面按步骤拆。

### 2.1 用户态：你敲 `docker run`

`dockerd` 做三类事情：

1. 解析镜像、命令、环境变量、端口映射、volume
2. 让 containerd 创建 container + task
3. 选择 runtime（默认 `runc`，也可换 gVisor/Kata 等实现 shim API 的 runtime） ([Docker Documentation][5])

### 2.2 内核：runc 这一步具体“怎么隔离”

#### A) 建 namespace：让容器“看起来像独立机器”

典型用到这些系统调用/flags（大方向你记住就行）：

* `clone()` / `clone3()`：创建新进程时带 `CLONE_NEW{PID,NS,NET,UTS,IPC,USER}` 等
* 或 `unshare()`：让当前进程脱离某些 namespace
* `setns()`：加入已有的 namespace（典型用于 `docker exec`）

> 结果：容器里 `ps` 看到自己的 PID 空间，网络/主机名/挂载点也像“独立的一套”。

#### B) 配 cgroup：让容器“吃多少资源”

v2 环境下（现在很常见），基本动作是：

* 在 `/sys/fs/cgroup/...` 创建目录（代表一个 cgroup）
* 写 `cgroup.subtree_control` 启用 controller（v2 需要显式 enable）([Linux内核文档][6])
* 写 `cpu.max / memory.max / io.max ...` 等限制（例如 `io.max` 可以限制某设备的 BPS/IOPS） ([Linux内核文档][6])
* 把容器进程 PID 写入 `cgroup.procs`

> 结果：CPU 会被 quota/weight 约束；内存到顶触发 cgroup OOM；IO 被 blk-mq/IO controller 限速/加权（你熟的 blkcg 思维可以直接迁移）。

#### C) 搭 rootfs：让容器“看到自己的文件系统”

这块是 NAS/存储背景最容易讲出彩的地方。

典型流程（简化）：

* 用 overlayfs 把镜像的只读层（lowerdir）叠到一个可写层（upperdir）上：`mount("overlay", ...)`
* 把需要的宿主资源 bind mount 进去（比如 volume）：`mount(MS_BIND, ...)`
* `pivot_root()` 或 `chroot()`：把进程的根目录切换到这个新 rootfs
* `mount()` 一些必要的伪文件系统：`proc`, `sysfs`, `tmpfs`（视配置）

> 结果：容器里看到的 `/` 是“镜像层 + 写层 + 挂载卷”的组合；写入文件通常触发 overlayfs 的 copy-up（从只读层复制到可写层）。

#### D) 安全收口：capabilities / seccomp / LSM

* **capabilities**：容器默认会去掉大量特权，只保留一小组能力（比如 net bind、chown 等按需）([GitHub][3])
* **seccomp**：Docker 默认 seccomp profile 会禁用一批系统调用（“默认可用但更安全”），你可以指定自定义 profile。([Docker Documentation][7])
* **SELinux/AppArmor**：由 runtime/daemon 配合 LSM 打标签/规则（dockerd 文档也把 SELinux 放在 runtime 责任里）。([Docker Documentation][1])

最后一步：`execve()` 启动容器的 PID 1（实际上就是你的应用进程）。

---

## 3) 容器跑起来后：日常“使用原理”怎么理解

你可以把容器运行后的行为按 4 条主线理解：

### 3.1 文件读写：overlayfs + page cache + 你的文件系统

* 读：先从 overlay 视图命中 page cache，否则走 VFS → overlayfs → lower/upper 的实际文件
* 写：写到 upper；如果写的是来自 lower 的文件，会 copy-up 到 upper 再改（这就是“写放大/碎片”在容器场景更明显的根源）

### 3.2 网络：netns + veth + bridge + NAT

* 每个容器在自己的 netns 里有接口（通常是 veth 一端）
* veth 的另一端在宿主机，接到 docker0/自定义 bridge
* `-p 8080:80` 这类端口映射，本质是宿主做 DNAT/iptables/nft 规则（以及可能的 userland proxy）

### 3.3 资源限制：cgroup 的“观测点”

你调试容器性能问题时，常看的就是这些：

* CPU throttling：`cpu.stat`（throttled 次数/时间）
* 内存：`memory.current / memory.events`（oom/oom_kill）
* IO：`io.stat / io.max`（你可以把它当 blkcg v2 的入口）([Linux内核文档][6])

### 3.4 `docker exec` / `docker stop`

* `docker exec`：用户态发请求 → containerd → runtime，把一个新进程通过 `setns()` 进入目标容器的 pid/mnt/net… namespace，然后 `execve()` 运行命令。
* `docker stop`：先发 SIGTERM，等超时再 SIGKILL；cgroup 负责把进程树约束在容器范围内，便于“一锅端”。

---

## 4) 面试时最加分的“内核视角总结”

你可以用这段 20 秒话术收尾：

> Docker 的核心就是把“一个进程”用 namespaces 做视图隔离、用 cgroup 做资源隔离、用 overlayfs/挂载体系构造 rootfs，再用 capabilities/seccomp/SELinux 做安全收口。dockerd 负责产品编排，通过 containerd 调 OCI runtime（默认 runc）把这些配置落到内核里。([Docker Documentation][1])

---

如果你愿意，我可以按“面试最常见追问”再给你一组针对性题库（每题配 30 秒答法），比如：**为什么容器里 PID 1 行为特殊、cgroup v2 subtree_control 为什么要 enable、CPU throttling 怎么定位、overlayfs 写放大怎么解释、port mapping 为啥影响性能、rootless 容器怎么做**。

[1]: https://docs.docker.com/reference/cli/dockerd/?utm_source=chatgpt.com "dockerd"
[2]: https://github.com/containerd/containerd/blob/main/docs/getting-started.md?utm_source=chatgpt.com "containerd/docs/getting-started.md at main"
[3]: https://github.com/opencontainers/runc/blob/master/libcontainer/SPEC.md?utm_source=chatgpt.com "runc/libcontainer/SPEC.md at main · opencontainers/runc"
[4]: https://docs.docker.com/engine/storage/containerd/?utm_source=chatgpt.com "containerd image store with Docker Engine"
[5]: https://docs.docker.com/engine/daemon/alternative-runtimes/?utm_source=chatgpt.com "Alternative container runtimes"
[6]: https://docs.kernel.org/admin-guide/cgroup-v2.html?utm_source=chatgpt.com "Control Group v2"
[7]: https://docs.docker.com/engine/security/seccomp/?utm_source=chatgpt.com "Seccomp security profiles for Docker"
