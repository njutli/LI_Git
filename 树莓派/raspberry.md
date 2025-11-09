gnu global版本
https://ftp.gnu.org/gnu/global/


a1151488180@gmail.com
gyesLLF1026...

应用专用密码
RaspberryPi01
nnrv zslv idyw wkum

thunderbird 专用密码
bjev mdml xyff oupb
bjevmdmlxyffoupb

配置 PC 作为外网访问代理
export http_proxy="http://192.168.43.192:4780"
export https_proxy="http://192.168.43.192:4780"

export http_proxy="http://192.168.0.108:7890"
export https_proxy="http://192.168.0.108:7890"

export http_proxy="http://192.168.0.109:4780"
export https_proxy="http://192.168.0.109:4780"

export http_proxy="http://192.168.0.100:4780"
export https_proxy="http://192.168.0.100:4780"

sudo global -u -v
sudo htags -v
sudo htags -fosa
sudo htags -fosav --update


[root@szvphis18908837 ~]# cd /home/httpd/
[root@szvphis18908837 httpd]# ls
auto_update_code.sh  code  daily_update_code.sh  gitproxy.sh  nohup.out
[root@szvphis18908837 httpd]# ls code/
css        fscryptctl  hulk-4.1   hulk-4.4   hulk-5.10-next  index.html     linux-next    mtd-utils  nfs-utils  olk-6.6      rh-7.3  rh-8.1
e2fsprogs  gssproxy    hulk-4.19  hulk-5.10  hulk-6.6        linux-fs-next  linux-stable  ndctl      olk-5.10   quota-tools  rh-7.5  xfsprogs-dev
[root@szvphis18908837 httpd]# ls code/css/
style.css
[root@szvphis18908837 httpd]#

#global -u
sudo rm -f GPATH GRTAGS GTAGS
sudo gtags
sudo htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux -m 'start_kernel'

# 6.6
rm -f GPATH GRTAGS GTAGS
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable-6.6 -m 'start_kernel'

# 5.10
rm -f GPATH GRTAGS GTAGS
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable-5.10 -m 'start_kernel'



升级版本？
```
[root@szvphis18908837 hulk-5.10]# global --version
global (GNU Global) 6.6.13
Powered by Berkeley DB 1.85.
Copyright (c) 1996-2024 Tama Communications Corporation
License GPLv3+: GNU GPL version 3 or later <http://www.gnu.org/licenses/gpl.html>
This is free software; you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.
[root@szvphis18908837 hulk-5.10]# htags --version
htags (GNU Global) 6.6.13
Powered by Berkeley DB 1.85.
Copyright (c) 1996-2024 Tama Communications Corporation
License GPLv3+: GNU GPL version 3 or later <http://www.gnu.org/licenses/gpl.html>
This is free software; you are free to change and redistribute it.
There is NO WARRANTY, to the extent permitted by law.
[root@szvphis18908837 hulk-5.10]#

[root@szvphis18908837 httpd]# ls
auto_update_code.sh  code  daily_update_code.sh  gitproxy.sh  nohup.out
[root@szvphis18908837 httpd]# ls code/
css        fscryptctl  hulk-4.1   hulk-4.4   hulk-5.10-next  index.html     linux-next    mtd-utils  nfs-utils  olk-6.6      rh-7.3  rh-8.1
e2fsprogs  gssproxy    hulk-4.19  hulk-5.10  hulk-6.6        linux-fs-next  linux-stable  ndctl      olk-5.10   quota-tools  rh-7.5  xfsprogs-dev
[root@szvphis18908837 httpd]# ls code/linux-next/
arch   certs    CREDITS  Documentation  fs     GRTAGS  HTML     init      ipc     Kconfig  lib       localversion-next  Makefile  net   README  samples  security  tools  virt
block  COPYING  crypto   drivers        GPATH  GTAGS   include  io_uring  Kbuild  kernel   LICENSES  MAINTAINERS        mm        Next  rust    scripts  sound     usr
[root@szvphis18908837 httpd]#

```


sudo vim /etc/apache2/sites-available/000-default.conf
sudo systemctl reload apache2


在6.6和主线代码目录下分别检查锚点
grep -n nfs4_state_manager HTML/fs/nfs/nfs4state.c.html | head
grep -n '<a name="L2582"' HTML/fs/nfs/nfs4state.c.html


lazy vim -- https://fanlumaster.github.io/2023/11/25/Lazyvim-configure-from-scratch/
重新装一套系统，使用global自带的web服务
格式化重试 —— nginx + global



多套代码，只有一套代码函数定义查询跳转正常：
```
原因：
raspberrypi@raspberrypi:~/code $ ls -ld /home /home/raspberrypi /home/raspberrypi/code
drwxr-xr-x 3 root root 4096 Jul 4 2024 /home
drwx------ 10 raspberrypi raspberrypi 4096 Nov 9 11:17 /home/raspberrypi
drwxr-xr-x 8 www-data www-data 4096 Nov 2 20:23 /home/raspberrypi/code
raspberrypi@raspberrypi:~/code $

/home/raspberrypi/code 被 www-data（Apache 的运行用户） 拥有。
这意味着所有你在这个目录下执行的命令（包括 gtags、htags）在文件写入、软链接、相对路径计算上，都落在一个“非预期用户”的权限边界内。
而 www-data 并不是你当前登录用户，也不是 sudo 默认切换的 root，因此在生成索引和 HTML 时，会导致一系列细微但灾难性的偏差。


解决办法：
sudo chown -R raspberrypi:raspberrypi /home/raspberrypi/code
sudo chmod -R u+rwX /home/raspberrypi/code
cd /home/raspberrypi/code/linux-stable
rm -f GPATH GRTAGS GTAGS
rm -rf HTML/
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable-6.6 -m 'start_kernel'

raspberrypi@raspberrypi:~/code/linux-stable $ ls -ld /home /home/raspberrypi /home/raspberrypi/code
drwxr-xr-x  3 root        root        4096 Jul  4  2024 /home
drwx-----x 11 raspberrypi raspberrypi 4096 Nov  9 15:56 /home/raspberrypi
drwxrwxr-x  9 raspberrypi raspberrypi 4096 Nov  9 13:48 /home/raspberrypi/code
raspberrypi@raspberrypi:~/code/linux-stable $

```





