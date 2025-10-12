a1151488180@gmail.com
gyesLLF1026...

应用专用密码
RaspberryPi01
nnrv zslv idyw wkum

配置 PC 作为外网访问代理
export http_proxy="http://192.168.43.192:26001"
export https_proxy="http://192.168.43.192:26001"

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
