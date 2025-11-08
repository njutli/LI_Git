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

export http_proxy="http://192.168.0.108:4780"
export https_proxy="http://192.168.0.108:4780"

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
sudo rm -f GPATH GRTAGS GTAGS
sudo gtags
sudo htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable-6.6 -m 'start_kernel'

# 5.10
sudo rm -f GPATH GRTAGS GTAGS
sudo gtags
sudo htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable-5.10 -m 'start_kernel'



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


重新装一套系统，使用global自带的web服务
格式化重试 —— nginx + global
lazy vim






