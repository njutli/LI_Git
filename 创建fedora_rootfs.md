# 下载fedora 37镜像

```
wget https://mirrors.tools.huawei.com/fedora/releases/37/Server/aarch64/images/Fedora-Server-37-1.7.aarch64.raw.xz --no-proxy --no-check-certificate
```

# 解压镜像
```
xz -d Fedora-Server-37-1.7.aarch64.raw.xz
```

# 生成一个新的base.raw

```
qemu-img create -f raw base.raw 10G
```

# 将Fedora-Server-37-1.7.aarch64.raw与base.raw作为两个盘分配加载到虚拟机中

```
/fs_harden/bin/qemu-system-aarch64 \
-M virt,gic-version=3,accel=kvm \
-cpu host -smp 4 -m 4096 \
-kernel /jenkins/workspace/olk-6.6_aarch64_vm_all/build/aarch64/arch/arm64/boot/Image \
-virtfs local,path=/jenkins/workspace/olk-6.6_aarch64_vm_all/build/aarch64/mod/lib/modules,readonly=on,mount_tag=modules,security_model=none \
-vga none -nographic \
-device virtio-scsi-pci \
-drive file=/jenkins/resources/image/olk-6.6/aarch64/base.qcow2,if=none,format=qcow2,cache=writeback,file.locking=off,id=root \
-device virtio-blk,drive=root,id=d_root \
-net nic,model=virtio,macaddr=DE:AB:EE:BF:0B:0C \
-net bridge,br=qemu_ci \
-drive file=/home/jenkins/yek/Fedora-Server-37-1.7.aarch64.raw,if=none,format=raw,cache=writeback,file.locking=off,id=b_fast_100 \
-device scsi-hd,drive=b_fast_100,id=d_b_fast_100 \
-drive file=/home/jenkins/yek/base.raw,if=none,format=raw,cache=writeback,file.locking=off,id=b_fast_101 \
-device scsi-hd,drive=b_fast_101,id=d_b_fast_101 \
-append "console=ttyAMA0 IP=192.168.250.111 root=/dev/vda rootfstype=ext4 rw" 
```



# 在虚拟机中对base.raw进行mkfs.ext4，然后mount，再将Fedora-Server-37-1.7.aarch64.raw对应/目录下所有文件全部copy到base.raw中

## 在物理机器中，挂载这个 Fedora 镜像，然后将其内容复制到 base.raw 文件中。

# 注意删除掉fstab中内容

# 重编内核加上zram，当前ci的config中不包括zram，会导致系统启动失败

# 切换到base.raw启动虚拟机

============================================================================================================

## 物理机上，制作base.raw

### 下载fedora 37镜像

~~~
wget https://mirrors.tools.huawei.com/fedora/releases/37/Server/aarch64/images/Fedora-Server-37-1.7.aarch64.raw.xz --no-proxy --no-check-certificate
~~~

### 解压镜像

~~~
xz -d Fedora-Server-37-1.7.aarch64.raw.xz
~~~

### 生成一个新的base.raw

~~~
qemu-img create -f raw base.raw 10G
~~~

要使用 `Fedora-Server-37-1.7.aarch64.raw` 镜像中的文件来准备 `base.raw` 作为新的虚拟机镜像文件，需要先挂载这个 `Fedora` 镜像，然后将其内容复制到 `base.raw` 文件中。以下是一些您可以遵循的基本步骤：

#### 步骤 1: 检查 `Fedora-Server-37-1.7.aarch64.raw` 镜像

在开始操作之前，您应该检查 `Fedora` 镜像是否完好无损。

```bash
file Fedora-Server-37-1.7.aarch64.raw
```

#### 步骤 2: 格式化 `base.raw` 文件

要格式化这个 `raw` 文件为 `ext4` 文件系统：

```bash
mkfs.ext4 base.raw
```

### 步骤 3: 挂载 `base.raw`

接下来，挂载您刚创建的 `base.raw` 文件系统：

```bash
mkdir /mnt/base
mount -o loop base.raw /mnt/base
```

### 步骤 4: 挂载 `Fedora-Server-37-1.7.aarch64.raw`

您需要将 `Fedora` 镜像挂载到一个临时目录，以便访问其内容。

```bash
mkdir /mnt/fedora
mount -o loop Fedora-Server-37-1.7.aarch64.raw /mnt/fedora
```

#### 挂载lVM分区

如果尝试挂载 Fedora-Server-37-1.7.aarch64.raw 文件时遇到了错误如下：

~~~bash
# mount -o loop Fedora-Server-37-1.7.aarch64.raw /mnt/fedora
mount: /mnt/fedora: wrong fs type, bad option, bad superblock on /dev/loop1, missing codepage or helper program, or other error.
~~~

这通常意味着镜像不是一个简单的文件系统镜像，而是包含了一个完整磁盘布局，包括分区表。因此，需要先找出镜像中包含的分区，然后挂载相应的分区，而不是整个镜像。

##### 1: 查找分区

使用 fdisk 或者 parted 来检查 Fedora-Server-37-1.7.aarch64.raw 镜像中的分区。

~~~bash
# fdisk -l Fedora-Server-37-1.7.aarch64.raw
Disk Fedora-Server-37-1.7.aarch64.raw: 7 GiB, 7516192768 bytes, 14680064 sectors
Units: sectors of 1 * 512 = 512 bytes
Sector size (logical/physical): 512 bytes / 512 bytes
I/O size (minimum/optimal): 512 bytes / 512 bytes
Disklabel type: dos
Disk identifier: 0x5c5e303a

Device                            Boot   Start      End  Sectors  Size Id Type
Fedora-Server-37-1.7.aarch64.raw1 *       2048  1230847  1228800  600M  6 FAT16
Fedora-Server-37-1.7.aarch64.raw2      1230848  3327999  2097152    1G 83 Linux
Fedora-Server-37-1.7.aarch64.raw3      3328000 14680063 11352064  5.4G 8e Linux LVM
# 或者
# parted Fedora-Server-37-1.7.aarch64.raw print
~~~

#### 2: 将分区关联到循环设备

如果您发现分区，需要使用 kpartx 来添加映射：

~~~bash
kpartx -av Fedora-Server-37-1.7.aarch64.raw
~~~

这会创建循环设备对应的分区映射，比如 /dev/mapper/loop1p1，使用`losetup -a`查看

#### 3: 检查LVM物理卷

运行以下命令来检查LVM物理卷：

~~~bash
# pvscan
  PV /dev/sde1             VG ci_vg           lvm2 [<1.75 TiB / 688.49 GiB free]
......
  PV /dev/mapper/loop1p3   VG fedora          lvm2 [5.41 GiB / 0    free]
~~~

这将显示所有已知的物理卷。如果您的设备是新映射的，您可能需要运行 pvscan --cache 来更新LVM的缓存。

#### 4: 检查卷组

运行以下命令来列出所有的卷组(Volume Groups, VG):

~~~bash
# vgscan
  Found volume group "ci_vg" using metadata type lvm2
......
  Found volume group "fedora" using metadata type lvm2
~~~

如果系统没有自动激活卷组，使用 `vgchange -ay` 激活它们。

#### 5: 列出逻辑卷

~~~bash
# lvs
  LV         VG     Attr       LSize    Pool Origin Data%  Meta%  Move Log Cpy%Sync Convert
  ci-workvol ci_vg  -wi-ao----    1.07t                                                    
......
  root       fedora -wi-ao----    5.41g     
~~~

#### 6: 挂载逻辑卷

找到想要挂载的逻辑卷后，挂载它到一个挂载点。例如，如果逻辑卷名为 `root` 并且属于卷组 `fedora`，挂载命令如下：

~~~bash
mount /dev/fedora/root /mnt/fedora -o ro # 只读挂载
~~~

之后可以访问挂载点 /mnt/fedora 下的文件了。

### 步骤 5: 复制文件

您现在可以将文件从已挂载的 `Fedora` 镜像复制到 `base.raw` 文件系统中了。使用 `rsync` 可以确保文件的权限和属性被保持。

```bash
rsync -axHAWX --numeric-ids --info=progress2 /mnt/fedora/ /mnt/base/
```

> 说明：不想看就跳过
> 
> `rsync` 是一个非常强大的文件复制工具，常用于备份和镜像操作。它的特点是能够复制文件的同时保持原有的权限、时间戳、软硬链接等，同时只传输文件的变化部分，从而提高效率。
> 
> 下面是对您提供的 `rsync` 命令参数的解释：
> 
> - `-a`: 归档模式，相当于 `-rlptgoD`，它保留了符号链接（如果有）、权限、时间戳、所有者（如果是超级用户）、组，并且会递归地拷贝目录。
> 
> - `-x`: 这个选项告诉 `rsync` 仅在一个文件系统内复制数据。这可以防止 `rsync` 尝试复制其他挂载的文件系统。
> 
> - `-H`: 保留硬链接。当复制含有硬链接的文件系统时，这个选项会确保目标文件系统中的相应文件也将通过硬链接连接。
> 
> - `-A`: 保留ACLs（访问控制列表），在支持它们的文件系统上，这会复制文件和目录的ACLs。
> 
> - `-W`: 传输整个文件，不只是变化的部分。这在某些情况下可能会更快。
> 
> - `-X`: 保留扩展属性，这包括了例如 SELinux 上下文和其他文件系统特定的元数据。
> 
> - `--numeric-ids`: 这个选项用于保持文件的数字用户ID和组ID，而不是试图将用户和组名称映射到本地用户和组名称。
> 
> - `--info=progress2`: 显示整个复制操作的总进度，而不是单个文件的进度。
> 
> 所以，整条命令 `rsync -axHAWX --numeric-ids --info=progress2 /mnt/fedora/ /mnt/base/` 的作用是，在一个文件系统内递归地复制 `/mnt/fedora/` 目录到 `/mnt/base/` 目录，同时保留文件的权限、时间戳、所有者、组、硬链接、ACLs、扩展属性，并且使用数字ID，且显示整个过程的总进度信息。这个命令通常在制作一个文件系统的完整备份时使用。


### 步骤 6：切换根目录

接下来要进行虚拟机的配置操作，为了方便操作，使用`chroot`命令切换根目录

**从这一步开始，切换到虚拟机工作目录**

~~~bash
chroot /mnt/base
~~~

使用`exit`退出。

### 步骤 7: 删除fstab中的内容

虚拟机启动 `base.raw` ，不需要fstab配置文件，需要清除 `/etc/fstab` 中的内容。

在chroot之后的环境中，编辑/etc/fstab`


### 步骤 8：配置软件源

#### 1: 替换配置文件url

通过使用sed命令替换配置文件字符串的方式

~~~bash
sed -i "s/#baseurl/baseurl/g" \
/etc/yum.repos.d/fedora.repo \
/etc/yum.repos.d/fedora-updates.repo \
/etc/yum.repos.d/fedora-modular.repo \
/etc/yum.repos.d/fedora-updates-modular.repo

sed -i "s/metalink/#metalink/g" \
/etc/yum.repos.d/fedora.repo \
/etc/yum.repos.d/fedora-updates.repo \
/etc/yum.repos.d/fedora-modular.repo \
/etc/yum.repos.d/fedora-updates-modular.repo

sed -i "s@http://download.fedoraproject.org/pub/fedora/linux@https://mirrors.tools.huawei.com/fedora@g" \
/etc/yum.repos.d/fedora.repo \
/etc/yum.repos.d/fedora-updates.repo \
/etc/yum.repos.d/fedora-modular.repo \
/etc/yum.repos.d/fedora-updates-modular.repo

sed -i "s@http://download.example/pub/fedora/linux@https://mirrors.tools.huawei.com/fedora@g" \
/etc/yum.repos.d/fedora.repo \
/etc/yum.repos.d/fedora-updates.repo \
/etc/yum.repos.d/fedora-modular.repo \
/etc/yum.repos.d/fedora-updates-modular.repo
~~~

#### 2: 配置hosts文件，关闭ssl认证

配置DNS，在`/etc/hosts`文件中，添加以下内容

~~~
7.223.219.58    mirrors.tools.huawei.com
10.140.8.225    code-sh.rnd.huawei.com
100.95.17.174   codehub-dg-y.huawei.com
~~~

关闭yum SSL验证，在`/etc/dnf/dnf.conf`文件中，添加以下内容

~~~
sslverify=false
~~~

过时内容：
~~~
#### 2: 添加yum代理，关闭ssl认证

在/etc/dnf/dnf.conf文件中，添加以下内容

~~~
sslverify=false
no_proxy=.huawei.com
proxy=http://10.175.127.227:8109
~~~

~~~

#### 3: 刷新缓存

~~~bash
yum makecache
~~~

### 步骤 9：安装必备软件包

~~~bash
sudo dnf groupinstall "Development Tools"

sudo dnf install make git gcc g++ libtirpc-devel glibc-static lzo-devel zstd libzstd-devel nvme-cli nvmetcli libudev-devel pip mtd-utils mtd-utils-ubi glibc glibc-devel glibc-static libtool libuuid-devel xfsprogs-devel acl attr automake bc dbench dump e2fsprogs fio gawk gdbm-devel indent kernel-devel libacl libaio-devel libcap-devel liburing-devel lvm2 psmisc python3 quota sed sqlite udftools xfsprogs btrfs-progs exfatprogs f2fs-tools ocfs2-tools xfsdump xfsprogs-devel libacl-devel sysstat

lrzsz  - ZModem文件传输协议 

~~~

配置pip源

添加以下内容到`~/.pip/pip.conf`

~~~
[global]
index-url = https://mirrors.tools.huawei.com/pypi/simple
trusted-host = mirrors.tools.huawei.com
timeout = 120
~~~

安装pyyaml

~~~
pip install pyyaml
~~~


### 步骤 10: 配置git

首先配置git，保证其能clone codehub仓库

在 /root/.gitconfig 中添加以下内容

~~~
[http]
        sslVerify = false
[core]
        quotepath = false
[user]
        email = youremail@huawei.com
        name = Your Name
[sendemail]
        smtpencryption  =
        smtpdomain = huawei.com
        smtpserver = smtpscn.huawei.com
        smtpserverport = 25
        smtpuser = 工号
        confirm = never
        suppresscc = all 
~~~

完成后，在codehub平台上，【个人头像】 -> 【设置】中添加ssh公钥 


### 步骤 11：清理挂载

退出前，请记得给root用户设置一个密码

~~~bash
passwd root
~~~

使用`exit`命令退出chroot环境。

~~~bash
exit
~~~

然后卸载挂载点

~~~bash
umount /mnt/fedora
umount /mnt/base
~~~

移除逻辑卷-物理卷

~~~bash
# 取消dm映射
kpartx -dv Fedora-Server-37-1.7.aarch64.raw


# 如果kpartx -dv 执行不成功，使用mount、losetup、lvdisplay、dmsetup等命令排查设备占用情况
~~~

接下来在虚拟机中操作

### 步骤 12：启动虚拟机

~~~bash
# /bin/bash
qemu-system-aarch64 \
-machine virt,gic-version=3,accel=kvm \
-cpu host \
-smp 4 \
-m 4096 \
-kernel /jenkins/wangzhaolong/olk-6.6_aarch64_vm_all/build/aarch64/arch/arm64/boot/Image \
-vga none \
-nographic \
-append "console=ttyAMA0 IP=192.168.250.123 root=/dev/vda rootfstype=ext4 rw" \
-device virtio-scsi-pci \
-drive file=/jenkins/wangzhaolong/base.raw,if=none,format=raw,cache=writeback,file.locking=off,id=root \
-device virtio-blk,drive=root,id=d_root \
-net nic,model=virtio,macaddr=DE:AB:EE:BF:12:A1 \
-net bridge,br=qemu_ci
~~~


#### 1. 配置网络

1. 禁用 NetworkManager-wait-online.service

~~~bash
sudo systemctl disable NetworkManager-wait-online.service
sudo systemctl stop NetworkManager-wait-online.service
~~~

2. 创建网络配置脚本

编辑 `/usr/local/bin/setup_vm.sh`，填入以下内容

~~~bash
#!/bin/sh

dev=$(ip link show | awk '/^[0-9]+: en/ {sub(":", "", $2); print $2}')
ip=$(awk '/IP=/ { print gensub(".*IP=([0-9.]+).*", "\\1", 1) }' /proc/cmdline)
if test -n "$ip"
then
        gw=$(echo $ip | sed 's/[.][0-9]\+$/.1/g')
        ip addr add $ip/24 dev $dev
        ip link set dev $dev up
        ip route add default via $gw dev $dev
fi

mkdir -p /tmp/modules
mount -t 9p -o trans=virtio modules /tmp/modules
if test "$?" -eq 0
then
        ver=$(uname -r)
        link=/lib/modules/$ver
        config=/tmp/modules/.config
        if test -h $link
        then
                rm -f $link
        fi
        ln -s /tmp/modules/$ver $link

        if [ -e $config ]
        then
                ln -s $config /boot/config-$ver
        fi
fi

# prevent /etc/export from being modified
old=/etc/exports
new=${old}.shadow
echo > $new
mount -o bind $new $old

echo 1 > /proc/sys/kernel/sysrq
echo 5 > /proc/sys/kernel/printk

mkdir -p /tmp/{test,scratch}

exit 0
~~~

添加执行权限

~~~bash
chmod a+x /usr/local/bin/setup_vm.sh
~~~

3. 创建开机自启服务

编辑 `/usr/lib/systemd/system/vm-setup.service`，填入以下内容

~~~
[Unit]
Description=VM Setup
Before=var-lib-nfs-rpc_pipefs.mount

[Service]
Type=oneshot
ExecStart=/usr/local/bin/setup_vm.sh

[Install]
WantedBy=default.target
~~~

启动+开机自启

~~~
systemctl start vm-setup.service 
systemctl enable vm-setup.service 
~~~

4. 配置ssh

允许root用户登陆

~~~
sudo sed -i 's/^#PermitRootLogin.*$/PermitRootLogin yes/' /etc/ssh/sshd_config
sudo sed -i 's/^PermitRootLogin.*$/PermitRootLogin yes/' /etc/ssh/sshd_config
sudo systemctl restart sshd
~~~

5. 解决chronyd服务启动报错

虚拟机网络配置在IPv4下

让chronyd仅在 IPv4 下运行，而不是在 IPv6 下运行：

~~~
ExecStart=/usr/sbin/chronyd -4
~~~




#### 2. 

~~~
/fs_harden/bin/qemu-system-aarch64 \
-M virt,gic-version=3,accel=kvm \
-cpu host -smp 4 -m 4096 \
-kernel /jenkins/workspace/olk-6.6_aarch64_vm_all/build/aarch64/arch/arm64/boot/Image \
-virtfs local,path=/jenkins/workspace/olk-6.6_aarch64_vm_all/build/aarch64/mod/lib/modules,readonly=on,mount_tag=modules,security_model=none \
-vga none -nographic \
-device virtio-scsi-pci \
-drive file=/jenkins/resources/image/olk-6.6/aarch64/base.qcow2,if=none,format=qcow2,cache=writeback,file.locking=off,id=root \
-device virtio-blk,drive=root,id=d_root \
-net nic,model=virtio,macaddr=DE:AB:EE:BF:0B:0C \
-net bridge,br=qemu_ci \
-drive file=/home/jenkins/yek/Fedora-Server-37-1.7.aarch64.raw,if=none,format=raw,cache=writeback,file.locking=off,id=b_fast_100 \
-device scsi-hd,drive=b_fast_100,id=d_b_fast_100 \
-drive file=/home/jenkins/yek/base.raw,if=none,format=raw,cache=writeback,file.locking=off,id=b_fast_101 \
-device scsi-hd,drive=b_fast_101,id=d_b_fast_101 \
-append "console=ttyAMA0 IP=192.168.250.111 root=/dev/vda rootfstype=ext4 rw"
~~~



### 步骤 12：clone 测试仓库


~~~bash
# blktests
git clone ssh://git@codehub-dg-y.huawei.com:2222/hulk/hulk_fs_harden/blktests.git

# cthon04
git clone ssh://git@codehub-dg-y.huawei.com:2222/hulk/hulk_fs_harden/cthon04.git

# device-mapper-test-suite

# fuse-xfstests

# libhugetlbfs


~~~



```bash
umount /mnt/fedora
umount /mnt/base
```

删除临时挂载点：

```bash
rmdir /mnt/fedora /mnt/base
```
