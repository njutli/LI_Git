https://markrepo.github.io/maintenance/2018/09/10/bcache/

bcache 是 Linux 主线内核的块缓存框架，通过将 SSD 作为 HDD 的缓存，实现读写加速、异步回写和元数据持久化，适合高性能存储系统。
它在块层运行，文件系统无感知，是一类“混合存储（hybrid storage）”关键组件

dm-cache 是基于 Device Mapper 框架的块级缓存系统，通过灵活的策略和元数据管理，在内核层实现 SSD + HDD 的分层加速。
它支持写回/写透模式、可与 LVM 集成，是企业级 Linux 系统中主流的混合存储实现方案。


| 项目     | **bcache**                                          | **dm-cache**                                             |
| ------ | --------------------------------------------------- | -------------------------------------------------------- |
| 所在层次   | 块层 (block layer)                                    | Device Mapper 层                                          |
| 核心入口   | 注册为独立块设备 `/dev/bcacheX`                             | 注册为 DM target `"cache"`                                  |
| 管理接口   | sysfs (`/sys/block/bcache*/bcache`) + `make-bcache` | device-mapper（`dmsetup`）或 LVM (`lvconvert --type cache`) |
| 内核路径   | `drivers/md/bcache/`                                | `drivers/md/dm-cache/`                                   |
| 用户感知方式 | 新的块设备出现                                             | 原设备通过 DM 逻辑层“套一层缓存”                                      |
