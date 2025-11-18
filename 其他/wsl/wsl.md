https://learn.microsoft.com/zh-cn/windows/wsl/install

```
// 查看所有安装的版本
wsl -l -v
// 启动指定版本
wsl -d FedoraLinux-43
// windowns对应目录
\\wsl$\Ubuntu\home\


密码
6-#a5258

# 可复制当前WSL2内核配置作为基础
cp /proc/config.gz .
gunzip config.gz
mv config .config
# 运行菜单式配置界面（可选）
make menuconfig
```

安装qemu

`sudo apt install qemu-system-x86`



**1. 完整镜像启动**

完整fedora镜像<br>
https://ftp.riken.jp/Linux/fedora/releases/43/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-43-1.6.x86_64.qcow2

```
wget https://ftp.riken.jp/Linux/fedora/releases/43/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-43-1.6.x86_64.qcow2

qemu-system-x86_64 \
  -enable-kvm -cpu host -m 4G \
  -kernel /home/i_ingfeng/linux/arch/x86_64/boot/bzImage \
  -append "root=/dev/vda4 rw rootflags=subvol=root console=ttyS0 selinux=0" \
  -drive file=/home/i_ingfeng/Fedora-Cloud-Base-Generic-43-1.6.x86_64.qcow2,if=virtio \
  -netdev user,id=n0 \
  -device virtio-net-pci,netdev=n0 \
  -nographic

append参数添加 init=/bin/bash 来设置密码

lilingfeng@LI-PC:/mnt/c/Users/Li Lingfeng$ openssl passwd -6
Password:
Verifying - Password:
$6$VDpadMHtD235yXWD$hgaKF5wdstB6P7JW/vtAjPp4W0ZZWhheK5kXnMxStzXKLhOipLyZdUm6wQFyHe1ViAzLXmF/EfG7D3nou2ia20
lilingfeng@LI-PC:/mnt/c/Users/Li Lingfeng$

密码 6-#a5258

bash: cannot set terminal process group (-1): Inappropriate ioctl for device
bash: no job control in this shell
bash-5.3# btrfs subvolume list /
ID 256 gen 18 top level 5 path root
ID 257 gen 9 top level 5 path home
ID 258 gen 14 top level 5 path var
bash-5.3# mount -o remount,rw /dev/vda4 /
bash-5.3# btrfs subvolume list /
ID 256 gen 18 top level 5 path root
ID 257 gen 9 top level 5 path home
ID 258 gen 14 top level 5 path var
bash-5.3# mkdir /sysroot
bash-5.3# mount -o subvol=root /dev/vda4 /sysroot
[  498.586200] BTRFS info: devid 1 device path /dev/root changed to /dev/vda4 scanned )
bash-5.3# ls /sysroot
afs   config.partids  grub2  lib64  opt   run   sys      usr
bin   dev             home   media  proc  sbin  sysroot  var
boot  etc             lib    mnt    root  srv   tmp
bash-5.3# mount --bind /dev /sysroot/dev
mount --bind /dev/pts /sysroot/dev/pts 2>/dev/null || true
mount -t proc none /sysroot/proc
mount -t sysfs none /sysroot/sys
bash-5.3#
bash-5.3# chroot /sysroot /bin/bash
bash: cannot set terminal process group (1): Inappropriate ioctl for device
bash: no job control in this shell
bash-5.3# passwd -S root
root L 2025-10-23 -1 -1 -1 -1
bash-5.3# passwd -u root
passwd: unlocking the password would result in a passwordless account.
You should set a password with usermod -p to unlock the password of this account.
bash-5.3# passwd -S root
bash-5.3# usermod -p '$6$VDpadMHtD235yXWD$hgaKF5wdstB6P7JW/vtAjPp4W0ZZWhheK5kXnMxStzXKLhOipLyZdUm6wQFyHe1ViAzLXmF/EfG7D3nou2ia20' root
bash-5.3# pLyZdUm6wQFyHe1ViAzLXmF/EfG7D3nou2ia20wdstB6P7JW/vtAjPp4W0ZZWhheK5kXnMx
bash-5.3#
bash-5.3# passwd -S root
root P 2025-11-14 -1 -1 -1 -1
bash-5.3#

// 配置网络后测试
[root@localhost ~]# ping -c 8.8.8.8
ping: invalid argument: '8.8.8.8'
[root@localhost ~]# ip a
1: lo: <LOOPBACK,UP,LOWER_UP> mtu 65536 qdisc noqueue state UNKNOWN group default qlen 1000
    link/loopback 00:00:00:00:00:00 brd 00:00:00:00:00:00
    inet 127.0.0.1/8 scope host lo
       valid_lft forever preferred_lft forever
    inet6 ::1/128 scope host noprefixroute
       valid_lft forever preferred_lft forever
2: ens3: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 qdisc pfifo_fast state UP group default qlen 1000
    link/ether 52:54:00:12:34:56 brd ff:ff:ff:ff:ff:ff
    altname enp0s3
    altname enx525400123456
    inet 10.0.2.15/24 brd 10.0.2.255 scope global dynamic noprefixroute ens3
       valid_lft 86267sec preferred_lft 86267sec
    inet6 fec0::924f:a848:434a:479/64 scope site dynamic noprefixroute
       valid_lft 86269sec preferred_lft 14269sec
    inet6 fe80::5910:ab77:4cf:67d1/64 scope link noprefixroute
       valid_lft forever preferred_lft forever
[root@localhost ~]# ping -c 3 8.8.8.8
PING 8.8.8.8 (8.8.8.8) 56(84) bytes of data.
64 bytes from 8.8.8.8: icmp_seq=1 ttl=255 time=226 ms
64 bytes from 8.8.8.8: icmp_seq=2 ttl=255 time=174 ms
64 bytes from 8.8.8.8: icmp_seq=3 ttl=255 time=204 ms

--- 8.8.8.8 ping statistics ---
3 packets transmitted, 3 received, 0% packet loss, time 2012ms
rtt min/avg/max/mdev = 174.107/201.343/225.807/21.197 ms
[root@localhost ~]# dnf update
Updating and loading repositories:
 Fedora 43 openh264 (From Cisco) - x86_64                                       100% | 510.0   B/s |   5.8 KiB |  00m12s
 Fedora 43 - x86_64                                                             100% |   1.6 MiB/s |  35.4 MiB |  00m22s
 Fedora 43 - x86_64 - Updates                                                   100% | 975.6 KiB/s |  10.5 MiB |  00m11s
Repositories loaded.
Package                                   Arch   Version                             Repository                     Size
Upgrading:
 alternatives                             x86_64 1.33-3.fc43                         updates                    62.2 KiB
   replacing alternatives                 x86_64 1.33-2.fc43                         0853d1013d5f4fe6901181b2c  62.2 KiB
 audit                                    x86_64 4.1.2-2.fc43                        updates                   500.5 KiB
...


安装软件
dnf install gcc
dnf install vim

```

**2. 通用 rootfs tarball启动**

下载一个 rootfs 压缩包
```
wget https://download.openvz.org/template/precreated/ubuntu-22.04-x86_64.tar.gz
```

手动生成镜像
```
dd if=/dev/zero of=rootfs.img bs=1M count=2048
mkfs.ext4 rootfs.img
mkdir /mnt/tmp
sudo mount -o loop rootfs.img /mnt/tmp
sudo tar xzf ubuntu-22.04-x86_64.tar.gz -C /mnt/tmp
sudo umount /mnt/tmp

```

启动
```
qemu-system-x86_64 -kernel arch/x86/boot/bzImage \
  -append "root=/dev/sda console=ttyS0 rw" \
  -hda rootfs.img \
  -nographic

```

**3. 现成的 QEMU 镜像项目**

```
git clone https://github.com/fedora-cloud/docker-brew-fedora.git

// 切换分支
lilingfeng@localhost:~/temp/docker-brew-fedora$ git remote -vv
origin  https://github.com/fedora-cloud/docker-brew-fedora.git (fetch)
origin  https://github.com/fedora-cloud/docker-brew-fedora.git (push)
lilingfeng@localhost:~/temp/docker-brew-fedora$ git branch -vv
* 43     3acc369 [origin/43] Update Fedora 43 - 2025-11-09
  master 34d63c6 [origin/master] Remove the use of --branched and --rawhide
lilingfeng@localhost:~/temp/docker-brew-fedora$ ls x86_64/
Dockerfile  etc  fedora-20251109.tar  usr
lilingfeng@localhost:~/temp/docker-brew-fedora$

// 解压启动文件
mkdir /tmp/rootfs
cd /tmp/rootfs
tar xf ~/temp/docker-brew-fedora/x86_64/fedora-20251109.tar

// 准备镜像
dd if=/dev/zero of=fedora-rootfs.img bs=1M count=4096
mkfs.ext4 fedora-rootfs.img

// 写入镜像
sudo mount -o loop fedora-rootfs.img /mnt
sudo cp -a /tmp/rootfs/* /mnt/
sudo umount /mnt

// 启动虚拟机
qemu-system-x86_64 \
  -enable-kvm -cpu host -m 4G \
  -kernel /home/lilingfeng/code/open_kernel/kernel/arch/x86_64/boot/bzImage \
  -append "root=/dev/vda console=ttyS0 rw selinux=0" \
  -drive file=/home/lilingfeng/temp/fedora-rootfs.img,format=raw,if=virtio \
  -nographic

// 设置密码
passwd

```



