https://lore.kernel.org/all/20250719164701.3147592-1-lilingfeng3@huawei.com/

【预设条件和测试步骤】
dmsetup create mydevice --table "0 2097152 linear /dev/sda 0"
dmsetup suspend mydevice
dmsetup reload mydevice --table "0 2097152 linear /dev/sdb 0"
dmsetup resume mydevice &
dmsetup remove mydevice
【测试用例代码（代码附件/“不涉及”）】
不涉及
【复现记录（可截图，需包含"uname -a"等版本信息）】
[ 378.082495][ T2694] =============================================================================
[ 378.085340][ T2694] BUG kmalloc-96 (Tainted: G W ): Object already free
[ 378.087819][ T2694] -----------------------------------------------------------------------------
[ 378.087819][ T2694]
[ 378.091251][ T2694] Allocated in alloc_fdtable+0xbe/0x110 age=8809 cpu=12 pid=2589
[ 378.093692][ T2694] __kmalloc_node+0x59/0x160
[ 378.095130][ T2694] alloc_fdtable+0xbe/0x110
[ 378.096545][ T2694] dup_fd+0x3d7/0x4a0
[ 378.097785][ T2694] copy_process+0xe18/0x1ac0
[ 378.099196][ T2694] kernel_clone+0x9a/0x6f0
[ 378.100590][ T2694] __se_sys_clone+0x6b/0xa0
[ 378.101999][ T2694] do_syscall_64+0x70/0x120
[ 378.103229][ T2694] entry_SYSCALL_64_after_hwframe+0x78/0xe2
[ 378.104512][ T2694] Freed in dm_ima_measure_on_device_remove+0x3b8/0x4d0 age=8796 cpu=14 pid=2694
[ 378.106448][ T2694] dev_remove+0xef/0x190
[ 378.107365][ T2694] ctl_ioctl+0x26e/0x380
[ 378.108276][ T2694] dm_ctl_ioctl+0xe/0x20
[ 378.109192][ T2694] __se_sys_ioctl+0x8b/0xc0
[ 378.110175][ T2694] do_syscall_64+0x70/0x120
[ 378.111151][ T2694] entry_SYSCALL_64_after_hwframe+0x78/0xe2
[ 378.112430][ T2694] Slab 0xfffff8f204755700 objects=40 used=25 fp=0xffff88f71d55c968 flags=0x17ffffc0000a40(workingset|slab|head|node=0|zone=2|lastcpupid=0x1fffff)
[ 378.115568][ T2694] Object 0xffff88f71d55c260 @offset=608 fp=0x0000000000000000
[ 378.115568][ T2694]
[ 378.117614][ T2694] Redzone ffff88f71d55c258: bb bb bb bb bb bb bb bb ........
[ 378.119487][ T2694] Object ffff88f71d55c260: 63 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b ckkkkkkkkkkkkkkk
[ 378.120977][ T2694] Object ffff88f71d55c270: 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b kkkkkkkkkkkkkkkk
[ 378.122475][ T2694] Object ffff88f71d55c280: 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b kkkkkkkkkkkkkkkk
[ 378.123959][ T2694] Object ffff88f71d55c290: 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b kkkkkkkkkkkkkkkk
[ 378.125457][ T2694] Object ffff88f71d55c2a0: 6a 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b jkkkkkkkkkkkkkkk
[ 378.126934][ T2694] Object ffff88f71d55c2b0: 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b 6b a5 kkkkkkkkkkkkkkk.
[ 378.128425][ T2694] Redzone ffff88f71d55c2c0: bb bb bb bb bb bb bb bb ........
[ 378.129813][ T2694] Padding ffff88f71d55c314: 5a 5a 5a 5a 5a 5a 5a 5a 5a 5a 5a 5a ZZZZZZZZZZZZ
[ 378.131222][ T2694] CPU: 14 PID: 2694 Comm: dmsetup Tainted: G W 6.6.0-g417ea5802ea9-dirty #205
[ 378.132493][ T2694] Hardware name: QEMU Standard PC (i440FX + PIIX, 1996), BIOS 1.16.3-2.fc40 04/01/2014
[ 378.133674][ T2694] Call Trace:
[ 378.134089][ T2694] <TASK>
[ 378.134460][ T2694] dump_stack_lvl+0x4a/0x80
[ 378.135027][ T2694] free_to_partial_list+0x2f0/0x580
[ 378.135672][ T2694] ? put_files_struct+0xeb/0x120
[ 378.136282][ T2694] put_files_struct+0xeb/0x120
[ 378.136871][ T2694] do_exit+0x2a4/0x570
[ 378.137382][ T2694] ? lock_release+0xdb/0x100
[ 378.137950][ T2694] do_group_exit+0x37/0xa0
[ 378.138503][ T2694] __x64_sys_exit_group+0x18/0x20
[ 378.139123][ T2694] do_syscall_64+0x70/0x120
[ 378.139685][ T2694] entry_SYSCALL_64_after_hwframe+0x78/0xe2
[ 378.140416][ T2694] RIP: 0033:0x7fb65dcd4ff8
[ 378.140965][ T2694] Code: Unable to access opcode bytes at 0x7fb65dcd4fce.
[ 378.141829][ T2694] RSP: 002b:00007ffcbd98fea8 EFLAGS: 00000246 ORIG_RAX: 00000000000000e7
[ 378.142861][ T2694] RAX: ffffffffffffffda RBX: 0000000000000000 RCX: 00007fb65dcd4ff8
[ 378.143842][ T2694] RDX: 0000000000000000 RSI: 000000000000003c RDI: 0000000000000000
[ 378.144816][ T2694] RBP: 00007fb65dfcb8a0 R08: 00000000000000e7 R09: fffffffffffffea8
[ 378.145795][ T2694] R10: 00007fb65d602d30 R11: 0000000000000246 R12: 00007fb65dfcb8a0
[ 378.146768][ T2694] R13: 00007fb65dfd0c00 R14: 0000000000000000 R15: 0000000000000000
[ 378.147742][ T2694] </TASK>
[ 378.148120][ T2694] FIX kmalloc-96: Object at 0xffff88f71d55c260 not freed
[1]+ Done dmsetup resume mydevice
[root@nfs_test3 ~]# [ 378.186290][ T339] =============================================================================
[ 378.187712][ T339] BUG kmalloc-96 (Tainted: G B W ): Wrong object count. Counter is 25 but counted were 35
[ 378.189450][ T339] -----------------------------------------------------------------------------
[ 378.189450][ T339]
[ 378.191233][ T339] Slab 0xfffff8f204755700 objects=40 used=25 fp=0xffff88f71d55c968 flags=0x17ffffc0000a40(workingset|slab|head|node=0|zone=2|lastcpupid=0x1fffff)
[ 378.194157][ T339] CPU: 14 PID: 339 Comm: kworker/14:3 Tainted: G B W 6.6.0-g417ea5802ea9-dirty #205
[ 378.196254][ T339] Hardware name: QEMU Standard PC (i440FX + PIIX, 1996), BIOS 1.16.3-2.fc40 04/01/2014
[ 378.198170][ T339] Workqueue: events blkg_free_workfn
[ 378.199233][ T339] Call Trace:
[ 378.199901][ T339] <TASK>
[ 378.200495][ T339] dump_stack_lvl+0x4a/0x80
[ 378.201406][ T339] slab_err+0xb3/0xf0
[ 378.202212][ T339] ? lock_acquire+0x27c/0x2b0
[ 378.203150][ T339] ? process_one_work+0x23b/0x5d0
[ 378.204162][ T339] ? worker_thread+0x1d6/0x3f0
[ 378.205123][ T339] on_freelist+0x165/0x240
[ 378.206016][ T339] free_to_partial_list+0x106/0x580
[ 378.207058][ T339] ? blk_put_queue+0x32/0x80
[ 378.207984][ T339] blk_put_queue+0x32/0x80
[ 378.208876][ T339] blkg_free_workfn+0x164/0x2c0
[ 378.209589][ T339] process_one_work+0x23b/0x5d0
[ 378.210257][ T339] ? process_one_work+0x1d9/0x5d0
[ 378.210952][ T339] worker_thread+0x1d6/0x3f0
[ 378.211818][ T339] ? __pfx_worker_thread+0x10/0x10
[ 378.212808][ T339] kthread+0xf8/0x130
[ 378.213398][ T339] ? __pfx_kthread+0x10/0x10
[ 378.214165][ T339] ret_from_fork+0x34/0x50
[ 378.215149][ T339] ? __pfx_kthread+0x10/0x10
[ 378.216167][ T339] ret_from_fork_asm+0x1b/0x30
[ 378.217234][ T339] </TASK>
[ 378.217934][ T339] FIX kmalloc-96: Object count adjusted
【验证记录（可截图，需包含"uname -a"等版本信息）】
Fedora 26 (Twenty Six)
Kernel 6.6.0-g972641bdcac4-dirty on an x86_64 (ttyS0)

nfs_test3 login: root
Password:
[ 26.128559][ T2242] systemd[2242]: memfd_create() called without MFD_EXEC or MFD_NOEXEC_SEAL set
Last login: Thu Jul 17 17:33:43 from 192.168.240.1
"root@nfs_test3 ~]# dmsetup create mydevice --table "0 2097152 linear /dev/sda 0"
[ 30.279772][ T2711] dm_ima_measure_on_table_load alloc buf ffff8a55c86e1db8, set to inactive_table.hash
[ 30.284560][ T2711] dm_ima_measure_on_device_resume free active_table.hash 0000000000000000
[ 30.286610][ T2711] dm_ima_measure_on_device_resume sleep before set active_table.hash 0000000000000000 to NULL...
[ 40.295380][ T2711] dm_ima_measure_on_device_resume slee done.
[ 40.296375][ T2711] dm_ima_measure_on_device_resume set md->ima.inactive_table.hash ffff8a55c86e1db8 to md->ima.active_table.hash
[root@nfs_test3 ~]#
[root@nfs_test3 ~]#
[root@nfs_test3 ~]# dmsetup suspend mydevice
"root@nfs_test3 ~]# dmsetup reload mydevice --table "0 2097152 linear /dev/sdb 0"
[ 55.272399][ T2837] dm_ima_measure_on_table_load alloc buf ffff8a55c86e0af8, set to inactive_table.hash
[root@nfs_test3 ~]# dmsetup resume mydevice &
[1] 2838
[root@nfs_test3 ~]# [ 65.951143][ T2838] dm_ima_measure_on_device_resume free active_table.hash ffff8a55c86e1db8
[ 65.954074][ T2838] dm_ima_measure_on_device_resume sleep before set active_table.hash ffff8a55c86e1db8 to NULL...

[root@nfs_test3 ~]#
[root@nfs_test3 ~]# dmsetup remove mydevice
[ 75.958342][ T2838] dm_ima_measure_on_device_resume slee done.
[ 75.959015][ T2838] dm_ima_measure_on_device_resume set md->ima.inactive_table.hash ffff8a55c86e0af8 to md->ima.active_table.hash
[ 75.959033][ T2839] dm_ima_measure_on_device_remove free active_table.hash 0000000000000000
[ 75.961048][ T2839] dm_ima_measure_on_device_remove free inactive_table.hash 0000000000000000
[1]+ Done dmsetup resume mydevice
[root@nfs_test3 ~]#
[root@nfs_test3 ~]#
