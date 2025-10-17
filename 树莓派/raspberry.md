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

sudo global -u -v
sudo htags -v
sudo htags -fosa
sudo htags -fosav --update


| 场景      | 命令                                                                     | 说明       |
| ------- | ---------------------------------------------------------------------- | -------- |
| 只改样式或布局 | `htags --frame --alphabet --title "Linux Source Browser"`              | 不需要重建数据库 |
| 改了源代码   | `gtags && htags --frame --alphabet --title "Linux Source Browser"`     | 全量重建     |
| 改了部分源文件 | `global -u && htags --frame --alphabet --title "Linux Source Browser"` | 增量更新     |

gtags && global -u && htags --frame --alphabet --title "Linux Source Browser"

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
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux -m 'start_kernel'

# 6.6
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable -m 'start_stable-kernel'

# 5.10
gtags
htags -DfFnvahoIstx --fixed-guide --auto-completion -t linux-stable -m 'start_stable-5.10-kernel'

sudo vim /etc/apache2/sites-available/000-default.conf
sudo systemctl reload apache2