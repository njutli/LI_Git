## 环境搭建（通过iscsi搭建dm-mpath环境）

### 打开相关内核开关
打开DM_MULTIPATH相关模块

### 安装依赖工具

```shell
# 通过iscsi搭建dm-mpath环境
yum install iscsi-initiator-utils device-mapper-multipath
```

### 配置

按照以下脚本进行环境配置

```shell
#!/bin/sh

ifconfig lo:0 127.0.0.2 up
ifconfig lo:1 127.0.0.3 up
ifconfig lo:2 127.0.0.4 up

# iscsi
cat << EOF > /etc/tgt/targets.conf
    <target iqn.2019-04.jenkins.disk>
            backing-store /dev/sda #使用sda作为后端设备
    </target>
EOF

# service tgtd restart
systemctl restart tgtd.service

# dm multipath
cat << EOF > /etc/multipath.conf
blacklist {
	devnode "^(ram|raw|loop|fd|md|dm-|sr|scd|st|pmem)[0-9]*"
	devnode "^hd[a-z][[0-9]*]"
	devnode "^vd[a-z][[0-9]*]"
	devnode "^cciss!c[0-9]d[0-9]*[p[0-9]*]"
}
defaults {
	path_checker            tur
	no_path_retry           18
	path_grouping_policy    group_by_prio
	prio                    const
	deferred_remove         yes
	uid_attribute           "ID_SERIAL"
	reassign_maps           no
	failback                immediate
	log_checker_err         once
}
multipaths {
	multipath {
		wwid                    360000000000000000e00000000010001
		alias                   mpath_disk          #磁盘别名
		path_selector           "service-time 0"    #负载均衡模式
	}
}
EOF

# service multipathd restart
# service iscsid restart
systemctl restart multipathd.service
systemctl restart iscsid.service

iscsiadm -m discovery -p 127.0.0.2 -t st
iscsiadm -m discovery -p 127.0.0.3 -t st
iscsiadm -m discovery -p 127.0.0.4 -t st
iscsiadm -m node -u  ##登出iscsi设备
iscsiadm -m node -u
iscsiadm -m node -l  ##登录iscsi设备，使用完毕后通过-u登出
```

### 结果

搭建完成后的效果如下

```shell
[root@localhost ~]# lsblk
NAME                       MAJ:MIN RM  SIZE RO TYPE  MOUNTPOINT
sda                          8:0    0  128G  0 disk
└─0QEMU_QEMU_HARDDISK_test 252:0    0  128G  0 mpath
sdb                          8:16   0  128G  0 disk
└─mpath_disk               252:1    0  128G  0 mpath
sdc                          8:32   0  128G  0 disk
└─mpath_disk               252:1    0  128G  0 mpath
sdd                          8:48   0  128G  0 disk
└─mpath_disk               252:1    0  128G  0 mpath
vda                        253:0    0   20G  0 disk
[root@localhost ~]# ls /dev/mapper/ -l
total 0
lrwxrwxrwx 1 root root       7 Jan 26 02:09 0QEMU_QEMU_HARDDISK_test -> ../dm-0
crw------- 1 root root 10, 236 Jan 26 02:09 control
lrwxrwxrwx 1 root root       7 Jan 26 02:10 mpath_disk -> ../dm-1
```

删除multipath盘

```shell
multipath -F  #删除所有multipath盘
```

创建multipath盘

```shell
multipath -v2  #根据/etc/multipath.conf创建盘符
```
