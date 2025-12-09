1、
写delete_controller删除controller
nvme_sysfs_delete应返回EBUSY到用户态

2、
dmsetup create test --table "0 20971520 linear /dev/sdd 0"
dmsetup create test1 --table "0 8388608 linear /dev/dm-0 0"
dmsetup create test2 --table "0 8388608 linear /dev/dm-0 10485760"
dmsetup create test3 --table "0 8388608 linear /dev/dm-1 0"
dmsetup create test4 --table "0 8388608 linear /dev/dm-2 0"

mkfs.ext4 /dev/mapper/test3
mount /dev/mapper/test3 /mnt/temp/
dd if=/dev/random of=testfile bs=1M count=1024

dmsetup suspend test
dmsetup reload test --table "0 2048 linear /dev/sdd 0"
dmsetup resume test

dd if=/dev/random of=testfile1 bs=1M count=1024

[root@localhost temp]# dd if=/dev/random of=testfile2 bs=1M count=2048
[ 1593.198176] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 305152)
[ 1593.224005] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 305607)
[ 1593.236012] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 306974)
[ 1593.237360] Buffer I/O error on device dm-3, logical block 306974
[ 1593.238223] Buffer I/O error on device dm-3, logical block 306975
[ 1593.238905] Buffer I/O error on device dm-3, logical block 306976
[ 1593.239553] Buffer I/O error on device dm-3, logical block 306977
[ 1593.240140] Buffer I/O error on device dm-3, logical block 306978
[ 1593.240771] Buffer I/O error on device dm-3, logical block 306979
[ 1593.241486] Buffer I/O error on device dm-3, logical block 306980
[ 1593.242140] Buffer I/O error on device dm-3, logical block 306981
[ 1593.242905] Buffer I/O error on device dm-3, logical block 306982
[ 1593.243631] Buffer I/O error on device dm-3, logical block 306983
[ 1593.331281] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 307200)
[ 1593.376984] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 307735)
[ 1593.511533] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 309248)
[ 1593.520170] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 311135)
[ 1593.627569] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 311296)
[ 1594.046025] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 313344)
[ 1594.227540] EXT4-fs warning (device dm-3): ext4_end_bio:343: I/O error 10 writing to inode 13 starting block 315392)
[ 1596.844584] Aborting journal on device dm-3-8.
[ 1596.845231] EXT4-fs (dm-3): ext4_do_writepages: jbd2_start: 2048 pages, ino 13; err -30
[ 1596.845440] Buffer I/O error on dev dm-3, logical block 491520, lost sync page write
[ 1596.846718] EXT4-fs error (device dm-3): ext4_journal_check_start:83: comm dd: Detected aborted journal
[ 1596.846932] JBD2: I/O error when updating journal superblock for dm-3-8.
[ 1596.855097] EXT4-fs (dm-3): Remounting filesystem read-only

[root@localhost temp]# md5sum testfile
md5sum: testfile: Input/output error

open("testfile", O_RDONLY)              = 3
fadvise64(3, 0, 0, POSIX_FADV_SEQUENTIAL) = 0
fstat(3, {st_mode=S_IFREG|0644, st_size=1073741824, ...}) = 0
read(3, 0x55e96785e260, 32768)          = -1 EIO (Input/output error)

[root@localhost ~]# ps aux | grep dd
root         2  0.0  0.0      0     0 ?        S    20:49   0:00 [kthreadd]
dbus       267  0.0  0.3  45844  4096 ?        Ss   20:49   0:00 /usr/bin/dbus-daemon --system --address=systemd: --nofork --nopidfile --systemd-activation --syslog-only
root      3421  2.1  0.2 115700  2688 pts/0    D+   21:04   0:06 dd if=/dev/random of=testfile1 bs=1M count=1024
root      3432  0.0  0.1 119468  2304 ttyS0    S+   21:09   0:00 grep --color=auto dd

[root@localhost ~]# cat /proc/3421/stack
[<0>] balance_dirty_pages+0x265/0x12b0
[<0>] balance_dirty_pages_ratelimited_flags+0x950/0x1420
[<0>] balance_dirty_pages_ratelimited+0x14/0x20
[<0>] generic_perform_write+0x19a/0x2c0
[<0>] ext4_buffered_write_iter+0xbb/0x1d0
[<0>] ext4_file_write_iter+0x5f/0xd10
[<0>] vfs_write+0x486/0x7b0
[<0>] ksys_write+0x7d/0x170
[<0>] __x64_sys_write+0x1e/0x30
[<0>] do_syscall_64+0x35/0x80
[<0>] entry_SYSCALL_64_after_hwframe+0x63/0xcd
[root@localhost ~]#

(gdb) list *(balance_dirty_pages+0x265/0x12b0)
0xffffffff81464b90 is in balance_dirty_pages (mm/page-writeback.c:1671).
1666     * If we're over `background_thresh' then the writeback threads are woken to
1667     * perform some writeout.
1668     */
1669    static int balance_dirty_pages(struct bdi_writeback *wb,
1670                                   unsigned long pages_dirtied, unsigned int flags)
1671    {
1672            struct dirty_throttle_control gdtc_stor = { GDTC_INIT(wb) };
1673            struct dirty_throttle_control mdtc_stor = { MDTC_INIT(wb, &gdtc_stor) };
1674            struct dirty_throttle_control * const gdtc = &gdtc_stor;
1675            struct dirty_throttle_control * const mdtc = mdtc_valid(&mdtc_stor) ?
(gdb)


[root@localhost ~]# lsblk
NAME        MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda           8:0    0  100G  0 disk
└─test      252:0    0   10G  0 dm
  ├─test1   252:1    0    4G  0 dm
  │ └─test3 252:3    0    4G  0 dm
  └─test2   252:2    0    4G  0 dm
    └─test4 252:4    0    4G  0 dm
sdb           8:16   0    5G  0 disk
sdc           8:32   0    8M  0 disk
vda         253:0    0   20G  0 disk /
[root@localhost ~]# dmsetup resume test
[root@localhost ~]# lsblk
NAME        MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda           8:0    0  100G  0 disk
└─test      252:0    0   10G  0 dm
  ├─test1   252:1    0    4G  0 dm
  │ └─test3 252:3    0    4G  0 dm
  └─test2   252:2    0    4G  0 dm
    └─test4 252:4    0    4G  0 dm
sdb           8:16   0    5G  0 disk
sdc           8:32   0    8M  0 disk
vda         253:0    0   20G  0 disk /
[root@localhost ~]# dmsetup suspend test
[root@localhost ~]#
"root@localhost ~]# dmsetup reload test --table "0 2048 linear /dev/sda 20971520
[root@localhost ~]# dmsetup resume test
[root@localhost ~]# lsblk
NAME        MAJ:MIN RM  SIZE RO TYPE MOUNTPOINT
sda           8:0    0  100G  0 disk
└─test      252:0    0    1M  0 dm
  ├─test1   252:1    0    4G  0 dm
  │ └─test3 252:3    0    4G  0 dm
  └─test2   252:2    0    4G  0 dm
    └─test4 252:4    0    4G  0 dm
sdb           8:16   0    5G  0 disk
sdc           8:32   0    8M  0 disk
vda         253:0    0   20G  0 disk /
[root@localhost ~]#

test可以reload成比自己下属设备更小

3、
nvme问题同步5.10 stable

4、
https://lore.kernel.org/all/2b466945-0ad1-43e9-5530-4bcd3d469e33@nvidia.com/
增加测试用例
参考 https://lore.kernel.org/all/20221230065424.19998-1-yukuai1@huaweicloud.com/



a13696b83da4f1e53e192ec3e239afd3a96ff747
+static int blk_iolatency_try_init(struct blkg_conf_ctx *ctx)
+{
+       static DEFINE_MUTEX(init_mutex);

ommit a13bd91be22318768d55470cbc0b0f4488ef9edf
Author: Yu Kuai <yukuai3@huawei.com>
Date:   Fri Apr 14 16:40:08 2023 +0800

    block/rq_qos: protect rq_qos apis with a new lock

Commit a13696b83da4 ("blk-iolatency: Make initialization lazy") adds
a mutex named "init_mutex" in blk_iolatency_try_init for the race
condition of initializing RQ_QOS_LATENCY.
Now a new lock has been add to struct request_queue by commit a13bd91be223
("block/rq_qos: protect rq_qos apis with a new lock"). And it has been
held in blkg_conf_open_bdev before calling blk_iolatency_init.
So it's not necessary to keep init_mutex in blk_iolatency_try_init, just
remove it.




快照失效打印增加设备名 dm-n

快照失效原因持久化到cow设备







nfs_umount 函数没有用


nfs_set_pgio_error
good_bytes 含义不明


include/linux/nfs_xdr.h
结构体指针定义格式不一致，星号位置不同

struct nfs_openargs
struct nfs_openres
struct nfs_open_confirmargs
struct nfs_closeargs
struct nfs_closeres
struct nfs_lock_args
struct nfs_lock_res
struct nfs_locku_args
struct nfs_locku_res
struct nfs_lockt_args


https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?id=8cfb9015280d49f9d92d5b0f88cedf5f0856c0fd

https://git.kernel.org/pub/scm/linux/kernel/git/torvalds/linux.git/commit/?id=0cb4d23ae08c48f6bf3c29a8e5c4a74b8388b960

客户端检测 offset+count 不超过 U64
服务端返回ERR_INVAL，客户端死循环？

commit 6da6680632792709cecf2b006f2fe3ca7857e791
Author: Ming Lei <ming.lei@redhat.com>
Date:   Wed May 15 09:31:56 2024 +0800

    blk-cgroup: fix list corruption from resetting io stat



commit ef45fe470e1e5410db4af87abc5d5055427945ac
Author: Boris Burkov <boris@bur.io>
Date:   Mon Jun 1 13:12:05 2020 -0700

    blk-cgroup: show global disk stats in root cgroup io.stat


commit ad7c3b41e86b59943a903d23c7b037d820e6270c
Author: Jinke Han <hanjinke.666@bytedance.com>
Date:   Mon May 8 01:06:31 2023 +0800

    blk-throttle: Fix io statistics for cgroup v1








The list corruption described in commit 6da668063279 ("blk-cgroup: fix
list corruption from resetting io stat") has no effect. It's unnecessary
to fix it.
As for cgroup v1, it does not use iostat any more after commit
ad7c3b41e86b("blk-throttle: Fix io statistics for cgroup v1"), so using
memset to clear iostat has no real effect.
As for cgroup v2, it will not call blkcg_reset_stats() to corrupt the
list.

The list of root cgroup can be used by both cgroup v1 and v2 while
non-root cgroup can't since it must be removed before switch between
cgroup v1 and v2.
So it may has effect if the list of root used by cgroup v2 was corrupted
after switching to cgroup v1, and switch back to cgroup v2 to use the
corrupted list again.
However, the root cgroup will not use the list any more after commit
ef45fe470e1e("blk-cgroup: show global disk stats in root cgroup io.stat").

Although this has no negative effect, it is not necessary. Remove the
related code.

Fixes: 6da668063279 ("blk-cgroup: fix list corruption from resetting io stat")



diff --git a/fs/nfs/nfs4proc.c b/fs/nfs/nfs4proc.c
index 62b73b9478f0..f048ab01e957 100644
--- a/fs/nfs/nfs4proc.c
+++ b/fs/nfs/nfs4proc.c
@@ -2233,11 +2233,25 @@ static int nfs4_open_reclaim(struct nfs4_state_owner *sp, struct nfs4_state *sta
        int ret;

        ctx = nfs4_state_find_open_context(state);
+       while(true) {
+       ifdebug(PROC) {
+               msleep(3000);
+               printk(KERN_WARNING "###################### %s %d", __func__, __LINE__);
+       }else
+               break;
+       }
        if (IS_ERR(ctx))
                return -EAGAIN;
        clear_bit(NFS_DELEGATED_STATE, &state->flags);
        nfs_state_clear_open_state_flags(state);
        ret = nfs4_do_open_reclaim(ctx, state);
+       while(true) {
+       ifdebug(PNFS) {
+               msleep(3000);
+               printk(KERN_WARNING "###################### %s %d", __func__, __LINE__);
+       }else
+               break;
+       }
        put_nfs_open_context(ctx);
        return ret;
 }
diff --git a/fs/nfs/nfs4state.c b/fs/nfs/nfs4state.c
index 41dda700de00..f6aba507bf59 100644
--- a/fs/nfs/nfs4state.c
+++ b/fs/nfs/nfs4state.c
@@ -1681,6 +1681,13 @@ static int nfs4_reclaim_open_state(struct nfs4_state_owner *sp, const struct nfs
 #endif /* CONFIG_NFS_V4_2 */
                refcount_inc(&state->count);
                spin_unlock(&sp->so_lock);
+               while(true) {
+                       ifdebug(STATE) {
+                               msleep(3000);
+                               printk(KERN_WARNING "###################### %s %d", __func__, __LINE__);
+                       }else
+                               break;
+               }
                status = __nfs4_reclaim_open_state(sp, state, ops);

                switch (status) {



mount -t nfs -o rw,vers=4.1 192.168.240.252:/sdb /mnt/sdb
exec 4<> /mnt/sdb/testfile
# 换另一个shell
rpcdebug -m nfs -s state proc pnfs
														# 服务端 stop + start 重置sequence
														service nfs stop
														service nfs start
														# 触发 reclaim
														service nfs stop
rpcdebug -m nfs -c state 		# 开启第一个，让 open context 引用加1
exec 4<&-
umount -l /mnt/sdb 					# kill 所有 task
rpcdebug -m nfs -c proc  		# 开启第二个，在 put_nfs_open_context 之前让 umount -f kill 掉所有 rpctask
rpcdebug -m nfs -c pnfs  		# 预期在这里 evict inode 调用 


mount -t nfs -o rw,vers=4.1 192.168.240.252:/sdb /mnt/sdb
重新挂载卡住
[root@localhost ~]# cat /proc/2905/stack
[<0>] rpc_wait_bit_killable+0x5c/0x2c0
[<0>] __rpc_execute+0x398/0xe60
[<0>] rpc_execute+0x279/0x370
[<0>] rpc_run_task+0x5fa/0x7b0
[<0>] nfs4_call_sync_custom+0x18/0xa0
[<0>] _nfs41_proc_secinfo_no_name.constprop.0+0x2c9/0x480
[<0>] nfs41_find_root_sec+0x168/0x950
[<0>] nfs4_proc_get_rootfh+0xac/0x160
[<0>] nfs4_get_rootfh+0xa6/0x260
[<0>] nfs4_server_common_setup+0x34a/0xb20
[<0>] nfs4_create_server+0xb18/0xe40
[<0>] nfs4_try_get_tree+0xef/0x2c0
[<0>] nfs_get_tree+0x132/0x1a0
[<0>] vfs_get_tree+0x93/0x300
[<0>] path_mount+0x1242/0x1c00
[<0>] do_mount+0xfc/0x110
[<0>] __se_sys_mount+0x22e/0x2f0
[<0>] do_syscall_64+0x33/0x40
[<0>] entry_SYSCALL_64_after_hwframe+0x67/0xd1
[root@localhost ~]#



commit 3b68e6ee3cbd4a474bcc7d2ac26812f86cdf333d
Author: NeilBrown <neilb@suse.com>
Date:   Wed Feb 14 12:15:06 2018 +1100

    SUNRPC: cache: ignore timestamp written to 'flush' file.

    The interface for flushing the sunrpc auth cache was poorly
    designed and has caused problems a number of times.

















[2024-11-11 20:29:21]  [root@localhost ~]# ls /mnt
[2024-11-11 20:29:25]  mnt/  mnt1/ mnt3/ 
[2024-11-11 20:29:25]  [root@localhost ~]# ls /mnt2mkdir -p /mnt2
[2024-11-11 20:29:33]  [root@localhost ~]# 
[2024-11-11 20:29:34]  [root@localhost ~]# mount /dev/sda /mnt2
[2024-11-11 20:29:34]  [  100.028167][ T3024] sget_fc 764 new superblock ffff8881288c9000 s_flags 268435456 fc->sb_flags 268435456
[2024-11-11 20:29:34]  [  100.122282][ T3024] vfs_create_mount 1226 &mnt->mnt; ffff8881215f4260
[2024-11-11 20:29:34]  [  100.123081][ T3024] do_add_mount 3404 path->mnt->mnt_sb ffff888129981000 newmnt->mnt.mnt_sb ffff8881288c9000 path->mnt->mnt_root ffff8881275c0008 path->dentry ffff88813b90a2d0
[2024-11-11 20:29:34]  [root@localhost ~]# echo "/mnt2 *(rw,no_root_squash,fsid=0)" > /etc/exports
[2024-11-11 20:29:34]  [root@localhost ~]# systemctl restart nfs-server
[2024-11-11 20:29:35]  [  100.719946][ T3030] sget_fc 764 new superblock ffff88812c071000 s_flags 0 fc->sb_flags 0
[2024-11-11 20:29:35]  [  100.724861][ T3030] vfs_create_mount 1226 &mnt->mnt; ffff8881215f7c60
[2024-11-11 20:29:35]  [  100.725549][ T3030] do_add_mount 3404 path->mnt->mnt_sb ffff888129981000 newmnt->mnt.mnt_sb ffff88812c071000 path->mnt->mnt_root ffff8881275c0008 path->dentry ffff888135147508
[2024-11-11 20:29:35]  [  100.781724][ T3031] sget_fc 764 new superblock ffff888132f6c000 s_flags 0 fc->sb_flags 0
[2024-11-11 20:29:35]  [  100.787310][ T3031] vfs_create_mount 1226 &mnt->mnt; ffff8881215f7060
[2024-11-11 20:29:35]  [  100.788065][ T3031] do_add_mount 3404 path->mnt->mnt_sb ffff888127e49000 newmnt->mnt.mnt_sb ffff888132f6c000 path->mnt->mnt_root ffff888110027010 path->dentry ffff888135209738
[2024-11-11 20:29:37]  [  102.763292][ T3051] NFSD: Using /var/lib/nfs/v4recovery as the NFSv4 state recovery directory
[2024-11-11 20:29:37]  [  102.770109][ T3051] NFSD: Using /var/lib/nfs/v4recovery as the NFSv4 state recovery directory
[2024-11-11 20:29:37]  [  102.771258][ T3051] ------------[ cut here ]------------
[2024-11-11 20:29:37]  [  102.771838][ T3051] kernel BUG at fs/nfsd/nfs4recover.c:534!
[2024-11-11 20:29:37]  [  102.772419][ T3051] Oops: invalid opcode: 0000 [#1] PREEMPT SMP KASAN PTI
[2024-11-11 20:29:37]  [  102.773095][ T3051] CPU: 12 UID: 0 PID: 3051 Comm: rpc.nfsd Not tainted 6.12.0-rc7-dirty #54
[2024-11-11 20:29:37]  [  102.773915][ T3051] Hardware name: QEMU Standard PC (i440FX + PIIX, 1996), BIOS ?-20190727_073836-buildvm-ppc64le-16.ppc.fedoraproject.org-3.fc31 04/01/2014
[2024-11-11 20:29:37]  [  102.775292][ T3051] RIP: 0010:nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.775927][ T3051] Code: 0f 85 b4 fa ff ff 48 c7 c2 c0 ba eb 97 be 52 03 00 00 48 c7 c7 20 bb eb 97 c6 05 cd 40 ab 03 01 e8 48 fd 66 ff e9 90 fa ff ff <0f> 0b 48 c7 c6 40 65 a7 99 48 c7 c7 40 ca eb 97 e8 de e7 68 ff 4c
[2024-11-11 20:29:37]  [  102.777826][ T3051] RSP: 0018:ffffc9000825fa20 EFLAGS: 00010286
[2024-11-11 20:29:37]  [  102.778411][ T3051] RAX: 0000000000000000 RBX: ffff888110744000 RCX: ffffffff967cce33
[2024-11-11 20:29:37]  [  102.779174][ T3051] RDX: dffffc0000000000 RSI: 0000000000000008 RDI: ffff8881107441f8
[2024-11-11 20:29:37]  [  102.779955][ T3051] RBP: ffff8881164a5880 R08: ffffffff96058701 R09: fffff5200104befe
[2024-11-11 20:29:37]  [  102.780727][ T3051] R10: fffff5200104befd R11: ffffc9000825f7ef R12: 1ffff9200104bf44
[2024-11-11 20:29:37]  [  102.781504][ T3051] R13: 0000000000000100 R14: ffff8881107441f8 R15: ffffffff97ebd540
[2024-11-11 20:29:37]  [  102.782298][ T3051] FS:  00007f5864395c80(0000) GS:ffff8883ae800000(0000) knlGS:0000000000000000
[2024-11-11 20:29:37]  [  102.783173][ T3051] CS:  0010 DS: 0000 ES: 0000 CR0: 0000000080050033
[2024-11-11 20:29:37]  [  102.783824][ T3051] CR2: 00007f4b0ba7c860 CR3: 00000001187d2000 CR4: 00000000000006f0
[2024-11-11 20:29:37]  [  102.784614][ T3051] DR0: 0000000000000000 DR1: 0000000000000000 DR2: 0000000000000000
[2024-11-11 20:29:37]  [  102.785367][ T3051] DR3: 0000000000000000 DR6: 00000000fffe0ff0 DR7: 0000000000000400
[2024-11-11 20:29:37]  [  102.786108][ T3051] Call Trace:
[2024-11-11 20:29:37]  [  102.786424][ T3051]  <TASK>
[2024-11-11 20:29:37]  [  102.786704][ T3051]  ? __die_body+0x1f/0x70
[2024-11-11 20:29:37]  [  102.787122][ T3051]  ? die+0x3d/0x60
[2024-11-11 20:29:37]  [  102.787489][ T3051]  ? do_trap+0x143/0x180
[2024-11-11 20:29:37]  [  102.787902][ T3051]  ? nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.788484][ T3051]  ? do_error_trap+0x92/0x140
[2024-11-11 20:29:37]  [  102.788930][ T3051]  ? nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.789497][ T3051]  ? nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.790078][ T3051]  ? handle_invalid_op+0x2c/0x40
[2024-11-11 20:29:37]  [  102.790576][ T3051]  ? nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.791165][ T3051]  ? exc_invalid_op+0x2f/0x50
[2024-11-11 20:29:37]  [  102.791633][ T3051]  ? asm_exc_invalid_op+0x1a/0x20
[2024-11-11 20:29:37]  [  102.792143][ T3051]  ? irq_work_sync+0xb1/0x140
[2024-11-11 20:29:37]  [  102.792603][ T3051]  ? nfsd4_legacy_tracking_init+0x243/0x7a0
[2024-11-11 20:29:37]  [  102.793199][ T3051]  ? nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.793774][ T3051]  ? __pfx_nfsd4_legacy_tracking_init+0x10/0x10
[2024-11-11 20:29:37]  [  102.794377][ T3051]  ? __rcu_read_unlock+0x6d/0x2a0
[2024-11-11 20:29:37]  [  102.794859][ T3051]  nfsd4_client_tracking_init+0x13c/0x5a0
[2024-11-11 20:29:37]  [  102.795427][ T3051]  ? __pfx_nfsd4_client_tracking_init+0x10/0x10
[2024-11-11 20:29:37]  [  102.796055][ T3051]  ? __list_add_valid_or_report+0x3a/0xe0
[2024-11-11 20:29:37]  [  102.796635][ T3051]  nfs4_state_start_net+0xe5/0x3f0
[2024-11-11 20:29:37]  [  102.797158][ T3051]  nfsd_svc+0x3a2/0x960
[2024-11-11 20:29:37]  [  102.797587][ T3051]  write_threads+0x1a3/0x330
[2024-11-11 20:29:37]  [  102.798059][ T3051]  ? __pfx_write_threads+0x10/0x10
[2024-11-11 20:29:37]  [  102.798580][ T3051]  ? __might_fault+0x78/0xc0
[2024-11-11 20:29:37]  [  102.799044][ T3051]  ? __pfx_lock_release+0x10/0x10
[2024-11-11 20:29:37]  [  102.799555][ T3051]  ? lock_is_held_type+0x9e/0x120
[2024-11-11 20:29:37]  [  102.800065][ T3051]  ? __might_fault+0x78/0xc0
[2024-11-11 20:29:37]  [  102.800537][ T3051]  ? should_fail_ex+0x82/0x2d0
[2024-11-11 20:29:37]  [  102.801014][ T3051]  ? _copy_from_user+0x79/0x90
[2024-11-11 20:29:37]  [  102.801503][ T3051]  ? __pfx_write_threads+0x10/0x10
[2024-11-11 20:29:37]  [  102.802019][ T3051]  nfsctl_transaction_write+0x76/0xa0
[2024-11-11 20:29:37]  [  102.802561][ T3051]  vfs_write+0x167/0x810
[2024-11-11 20:29:37]  [  102.802984][ T3051]  ? __pfx_vfs_write+0x10/0x10
[2024-11-11 20:29:37]  [  102.803465][ T3051]  ? do_sys_openat2+0x266/0x350
[2024-11-11 20:29:37]  [  102.803947][ T3051]  ? __pfx_do_sys_openat2+0x10/0x10
[2024-11-11 20:29:37]  [  102.804468][ T3051]  ? kasan_save_track+0x14/0x30
[2024-11-11 20:29:37]  [  102.804951][ T3051]  ? __pfx___call_rcu_common.constprop.0+0x10/0x10
[2024-11-11 20:29:37]  [  102.805600][ T3051]  ksys_write+0xc7/0x170
[2024-11-11 20:29:37]  [  102.806022][ T3051]  ? __pfx_ksys_write+0x10/0x10
[2024-11-11 20:29:37]  [  102.806517][ T3051]  ? mark_held_locks+0x24/0x90
[2024-11-11 20:29:37]  [  102.806986][ T3051]  ? do_syscall_64+0x38/0x180
[2024-11-11 20:29:37]  [  102.807458][ T3051]  do_syscall_64+0x70/0x180
[2024-11-11 20:29:37]  [  102.807904][ T3051]  entry_SYSCALL_64_after_hwframe+0x76/0x7e
[2024-11-11 20:29:37]  [  102.808470][ T3051] RIP: 0033:0x7f5863500130
[2024-11-11 20:29:37]  [  102.808906][ T3051] Code: 73 01 c3 48 8b 0d 58 ed 2c 00 f7 d8 64 89 01 48 83 c8 ff c3 66 0f 1f 44 00 00 83 3d b9 45 2d 00 00 75 10 b8 01 00 00 00 0f 05 <48> 3d 01 f0 ff ff 73 31 c3 48 83 ec 08 e8 3e f3 01 00 48 89 04 24
[2024-11-11 20:29:37]  [  102.810797][ T3051] RSP: 002b:00007ffc6fb66ac8 EFLAGS: 00000246 ORIG_RAX: 0000000000000001
[2024-11-11 20:29:37]  [  102.811620][ T3051] RAX: ffffffffffffffda RBX: 0000000000000003 RCX: 00007f5863500130
[2024-11-11 20:29:37]  [  102.812388][ T3051] RDX: 0000000000000002 RSI: 000055e123409620 RDI: 0000000000000003
[2024-11-11 20:29:37]  [  102.813154][ T3051] RBP: 000055e123409620 R08: 000055e123206704 R09: 0000000000000000
[2024-11-11 20:29:37]  [  102.813931][ T3051] R10: 0000000000000000 R11: 0000000000000246 R12: 0000000000000000
[2024-11-11 20:29:37]  [  102.814703][ T3051] R13: 000055e12b785010 R14: 0000000000000001 R15: 0000000000020000
[2024-11-11 20:29:37]  [  102.815478][ T3051]  </TASK>
[2024-11-11 20:29:37]  [  102.815779][ T3051] Modules linked in:
[2024-11-11 20:29:37]  [  102.816189][ T3051] ---[ end trace 0000000000000000 ]---
[2024-11-11 20:29:37]  [  102.816730][ T3051] RIP: 0010:nfsd4_legacy_tracking_init+0x73d/0x7a0
[2024-11-11 20:29:37]  [  102.817375][ T3051] Code: 0f 85 b4 fa ff ff 48 c7 c2 c0 ba eb 97 be 52 03 00 00 48 c7 c7 20 bb eb 97 c6 05 cd 40 ab 03 01 e8 48 fd 66 ff e9 90 fa ff ff <0f> 0b 48 c7 c6 40 65 a7 99 48 c7 c7 40 ca eb 97 e8 de e7 68 ff 4c
[2024-11-11 20:29:37]  [  102.819276][ T3051] RSP: 0018:ffffc9000825fa20 EFLAGS: 00010286
[2024-11-11 20:29:37]  [  102.819892][ T3051] RAX: 0000000000000000 RBX: ffff888110744000 RCX: ffffffff967cce33
[2024-11-11 20:29:37]  [  102.820693][ T3051] RDX: dffffc0000000000 RSI: 0000000000000008 RDI: ffff8881107441f8
[2024-11-11 20:29:37]  [  102.821456][ T3051] RBP: ffff8881164a5880 R08: ffffffff96058701 R09: fffff5200104befe
[2024-11-11 20:29:37]  [  102.822223][ T3051] R10: fffff5200104befd R11: ffffc9000825f7ef R12: 1ffff9200104bf44
[2024-11-11 20:29:37]  [  102.823009][ T3051] R13: 0000000000000100 R14: ffff8881107441f8 R15: ffffffff97ebd540
[2024-11-11 20:29:37]  [  102.823804][ T3051] FS:  00007f5864395c80(0000) GS:ffff8883ae800000(0000) knlGS:0000000000000000
[2024-11-11 20:29:37]  [  102.824692][ T3051] CS:  0010 DS: 0000 ES: 0000 CR0: 0000000080050033
[2024-11-11 20:29:37]  [  102.825350][ T3051] CR2: 00007f4b0ba7c860 CR3: 00000001187d2000 CR4: 00000000000006f0
[2024-11-11 20:29:37]  [  102.826139][ T3051] DR0: 0000000000000000 DR1: 0000000000000000 DR2: 0000000000000000
[2024-11-11 20:29:37]  [  102.826936][ T3051] DR3: 0000000000000000 DR6: 00000000fffe0ff0 DR7: 0000000000000400
[2024-11-11 20:29:37]  [  102.827726][ T3051] Kernel panic - not syncing: Fatal exception
[2024-11-11 20:29:37]  [  102.828934][ T3051] Kernel Offset: 0x14c00000 from 0xffffffff81000000 (relocation range: 0xffffffff80000000-0xffffffffbfffffff)
[2024-11-11 20:29:37]  [  102.830074][ T3051] ---[ end Kernel panic - not syncing: Fatal exception ]---

sh start222.sh
去掉BUG_ON调试，什么情况会调用两次，初始化两次rec_file

/home/lilingfeng/rootfs/rootfs_new_ext4.gz.bak



nfs4_setup_state_renewal
函数返回值没地方会判断





输入：rpc_task 地址
输出：rpc_task 所在队列，该队列中在输入 rpc_task 前的其他 rpc_task 地址
https://blog.csdn.net/dog250/article/details/107997905
crash 工具解析 rpc_task
rpc_init_wait_queue



nfs 解决 /proc/self/mountstats 查出的是否只读显示不对问题
show_stats 回调只有nfs和cifs用，且 cifs_show_stats 回调为空，改函数参数


v3有问题，第二次读写挂载，还是生成只读挂载点
# 下游问题场景引申 ———— 社区存在问题
# 预期第一个挂载点ro，第二个挂载点rw
echo "downstream issue 1-1"
echo "/mnt/sdb *(rw,no_root_squash)" > /etc/exports
echo "/mnt/sdb/test_dir2 *(ro,no_root_squash)" >> /etc/exports
systemctl restart nfs-server
mount -t nfs -o ro,vers=3 127.0.0.1:/mnt/sdb/test_dir2 /mnt/test_mp2
mount | grep nfs # 一个挂载点
mount -t nfs -o rw,vers=3 127.0.0.1:/mnt/sdb/test_dir1 /mnt/test_mp1
mount | grep nfs # 两个挂载点
echo 123 > /mnt/test_mp1/testfile # 写成功
echo 123 > /mnt/test_mp2/testfile # 写失败
mount -t nfs -o remount,ro,vers=3 127.0.0.1:/mnt/sdb/test_dir2 /mnt/test_mp2
mount | grep nfs # 两个挂载点
echo 456 > /mnt/test_mp1/testfile # 写成功
echo 456 > /mnt/test_mp2/testfile # 写失败
umount /mnt/test_mp1
umount /mnt/test_mp2

[root@nfs_test2 test]# mount /dev/sdb /mnt/sdb
[root@nfs_test2 test]# systemctl restart nfs-server
nt/test_mp2est2 test]# mount -t nfs -o ro,vers=3 127.0.0.1:/mnt/sdb/test_dir2 /mn
[root@nfs_test2 test]# mount | grep nfs
sunrpc on /var/lib/nfs/rpc_pipefs type rpc_pipefs (rw,relatime)
nfsd on /proc/fs/nfsd type nfsd (rw,relatime)
127.0.0.1:/mnt/sdb/test_dir2 on /mnt/test_mp2 type nfs (ro,relatime,vers=3,rsize=1048576,wsize=1048576,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mou)
nt/test_mp1est2 test]# mount -t nfs -o rw,vers=3 127.0.0.1:/mnt/sdb/test_dir1 /mn
[root@nfs_test2 test]# mount | grep nfs
sunrpc on /var/lib/nfs/rpc_pipefs type rpc_pipefs (rw,relatime)
nfsd on /proc/fs/nfsd type nfsd (rw,relatime)
127.0.0.1:/mnt/sdb/test_dir2 on /mnt/test_mp2 type nfs (ro,relatime,vers=3,rsize=1048576,wsize=1048576,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mou)
127.0.0.1:/mnt/sdb/test_dir1 on /mnt/test_mp1 type nfs (ro,relatime,vers=3,rsize=1048576,wsize=1048576,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mou)
[root@nfs_test2 test]#

v2有问题，第二次读写挂载后也是只读
# 下游问题场景引申 ———— 社区存在问题
# 预期第一个挂载点ro，第二个挂载点rw
echo "downstream issue 1-1"
echo "/mnt/sdb *(rw,no_root_squash)" > /etc/exports
echo "/mnt/sdb/test_dir2 *(ro,no_root_squash)" >> /etc/exports
systemctl restart nfs-server
mount -t nfs -o ro,vers=2 127.0.0.1:/mnt/sdb/test_dir2 /mnt/test_mp2
mount | grep nfs # 一个挂载点
mount -t nfs -o rw,vers=2 127.0.0.1:/mnt/sdb/test_dir1 /mnt/test_mp1
mount | grep nfs # 两个挂载点
echo 123 > /mnt/test_mp1/testfile # 写成功
echo 123 > /mnt/test_mp2/testfile # 写失败
mount -t nfs -o remount,ro,vers=2 127.0.0.1:/mnt/sdb/test_dir2 /mnt/test_mp2
mount | grep nfs # 两个挂载点
echo 456 > /mnt/test_mp1/testfile # 写成功
echo 456 > /mnt/test_mp2/testfile # 写失败
umount /mnt/test_mp1
umount /mnt/test_mp2

[root@nfs_test2 test]# mount /dev/sdb /mnt/sdb
[root@nfs_test2 test]# echo "/mnt/sdb *(rw,no_root_squash)" > /etc/exports
ports@nfs_test2 test]# echo "/mnt/sdb/test_dir2 *(ro,no_root_squash)" >> /etc/exp
[root@nfs_test2 test]# systemctl restart nfs-server
nt/test_mp2est2 test]# mount -t nfs -o ro,vers=2 127.0.0.1:/mnt/sdb/test_dir2 /mn
[root@nfs_test2 test]# mount | grep nfs
sunrpc on /var/lib/nfs/rpc_pipefs type rpc_pipefs (rw,relatime)
nfsd on /proc/fs/nfsd type nfsd (rw,relatime)
127.0.0.1:/mnt/sdb/test_dir2 on /mnt/test_mp2 type nfs (ro,relatime,vers=2,rsize=8192,wsize=8192,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mountvers)
nt/test_mp1est2 test]# mount -t nfs -o rw,vers=2 127.0.0.1:/mnt/sdb/test_dir1 /mn
[root@nfs_test2 test]# mount | grep nfs
sunrpc on /var/lib/nfs/rpc_pipefs type rpc_pipefs (rw,relatime)
nfsd on /proc/fs/nfsd type nfsd (rw,relatime)
127.0.0.1:/mnt/sdb/test_dir2 on /mnt/test_mp2 type nfs (ro,relatime,vers=2,rsize=8192,wsize=8192,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mountvers)
127.0.0.1:/mnt/sdb/test_dir1 on /mnt/test_mp1 type nfs (ro,relatime,vers=2,rsize=8192,wsize=8192,namlen=255,hard,proto=tcp,timeo=600,retrans=2,sec=sys,mountaddr=127.0.0.1,mountvers)
[root@nfs_test2 test]#

v2 第二个挂载点只读
echo "issue 2.9"
echo "issue 2.10"
echo "issue 2.11"
echo "issue 2.12"



state manager 进程名优化，尽量保存"manager"的字样，方便识别state manager进程


