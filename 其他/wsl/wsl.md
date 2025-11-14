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
wget https://download.fedoraproject.org/pub/fedora/linux/releases/40/Cloud/x86_64/images/Fedora-Cloud-Base-40-1.14.x86_64.qcow2

qemu-system-x86_64 \
  -enable-kvm -m 4G -smp 4 -cpu host \
  -kernel /path/to/arch/x86/boot/bzImage \
  -append "root=/dev/sda1 console=ttyS0 selinux=0 rw" \
  -drive file=Fedora-Cloud-Base-40-1.14.x86_64.qcow2,format=qcow2 \
  -nographic

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
  -kernel /path/to/bzImage \
  -append "root=/dev/sda console=ttyS0 rw selinux=0" \
  -drive file=fedora-rootfs.img,format=raw,if=virtio \
  -nographic

// 设置密码
passwd

```



