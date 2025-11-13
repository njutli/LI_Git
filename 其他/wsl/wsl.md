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