https://lore.kernel.org/all/1c42a7fd9677ad1aa9a3a53eda738b3a6da3728e.camel@kernel.org/
test
ifdebug(PROC)
rpcdebug -m nfs -c proc

mkfs.ext4 -F /dev/sdb
mount /dev/sdb /mnt/sdb
echo "/mnt *(rw,no_root_squash,fsid=0)" > /etc/exports
echo "/mnt/sdb *(rw,no_root_squash,fsid=1)" >> /etc/exports
systemctl restart nfs-server
echo 123 > /mnt/sdb/testfile

echo 0xffff > /proc/sys/sunrpc/nfs_debug
echo 0xffff > /proc/sys/sunrpc/nfsd_debug

echo 0xffff > /proc/sys/sunrpc/rpc_debug
echo 0 > /proc/sys/sunrpc/rpc_debug

 438                 { NFS_MOUNT_SOFT, ",soft", "" },
 439                 { NFS_MOUNT_SOFTERR, ",softerr", "" },

server->client->cl_softerr = 1;
server->client->cl_softrtry = 1;

task->tk_flags |= RPC_TASK_SOFT;
task->tk_flags |= RPC_TASK_TIMEOUT;

#define RPC_IS_SOFT(t)          ((t)->tk_flags & (RPC_TASK_SOFT|RPC_TASK_TIMEOUT))
 
rpc_check_timeout
 __rpc_call_rpcerror // RPC_IS_SOFT
  rpc_exit // 退出 rpc_task，根据是否有RPC_TASK_TIMEOUT决定返回ETIMEOUT还是EIO

以call_bind_status为例
call_bind_status
 task->tk_action = call_bind
 // 出现timeout后调用
 rpc_check_timeout
  // 如果有 RPC_IS_SOFT 则直接退出
  // 如果没有 RPC_IS_SOFT 则继续 call_bind --> call_bind_status 的循环

--- a/fs/nfs_common/grace.c
+++ b/fs/nfs_common/grace.c
@@ -31,9 +31,10 @@ locks_start_grace(struct net *net, struct lock_manager *lm)
        struct list_head *grace_list = net_generic(net, grace_net_id);

        spin_lock(&grace_lock);
-       if (list_empty(&lm->list))
+       if (list_empty(&lm->list)) {
+               printk("%s add lm %px to grace_list\n", __func__, lm);
                list_add(&lm->list, grace_list);
-       else
+       } else
                WARN(1, "double list_add attempt detected in net %x %s\n",
                     net->ns.inum, (net == &init_net) ? "(init_net)" : "");
        spin_unlock(&grace_lock);
@@ -55,6 +56,7 @@ void
 locks_end_grace(struct lock_manager *lm)
 {
        spin_lock(&grace_lock);
+       printk("%s remove lm %px to grace_list\n", __func__, lm);
        list_del_init(&lm->list);
        spin_unlock(&grace_lock);
 }

nfs怎么防止读到过期数据（服务端数据已更新，客户端本地缓存没更新）
关键函数 nfs_update_inode nfs_revalidate_mapping

客户端读打开文件时会拿到读代理，在持有代理的状态下可以直接使用本地文件mapping里的缓存
当其他客户端要改服务端文件时，会请求写代理，服务端会回收当前文件的读代理
当客户端再次打开文件或读文件时发现代理无效，会更新缓存

如果读客户端长时间不返回deleg，写客户端也可以正常打开文件？
// 服务端返回 nfserr_jukebox ，触发客户端重试
nfsd4_open
 nfsd4_process_open2
  nfs4_get_vfs_file
   nfsd_file_acquire_opened
    nfsd_file_do_acquire // 返回 nfserr_jukebox
     nfsd_open_verified
      __nfsd_open
       nfsd_open_break_lease
        break_lease
         __break_lease // 返回  -EWOULDBLOCK
  
// 客户端打开文件
nfs4_file_open
 nfs4_atomic_open // NFS_PROTO(dir)->open_context
  nfs4_do_open
   _nfs4_do_open // 服务端返回 NFS4ERR_DELAY
    _nfs4_open_and_get_state
     _nfs4_proc_open
      nfs4_run_open_task
   nfs4_handle_exception
    // exception->retry = 1
   // 客户端不断重试

// 服务端反向调用回收 deleg
nfsd4_cb_recall_prepare
 dp->dl_time = ktime_get_boottime_seconds()
 list_add_tail // dp->dl_recall_lru 加入 nn->del_recall_lru 链表

// 预期客户端返还 deleg ，将 deleg 从 dl_recall_lru 链表中异常
nfsd4_delegreturn
 destroy_delegation
  unhash_delegation_locked
   list_del_init // dp->dl_recall_lru 

// 由于客户端没有返回 deleg ，服务端在定时任务中发现这个异常的 deleg ，将其加入 cl_revoked 链表
nfs4_laundromat
 // 遍历 nn->del_recall_lru
 state_expired // deleg 超时 <-- 服务端callback请求失败，或callbakc成功客户端未及时返回 deleg
 revoke_delegation
  list_add // dp->dl_recall_lru 加入 cl_revoked 链表
  destroy_unhashed_deleg // 同时服务端在超时后从 flc_lease 链表中移除锁
   nfs4_unlock_deleg_lease
    kernel_setlease // F_UNLCK
     generic_setlease
      generic_delete_lease
       lease_modify // fl->fl_lmops->lm_change
        locks_delete_lock_ctx

// 之后客户端再次发送open请求，服务端返回OK
nfsd4_open
 nfsd4_process_open2
  nfs4_get_vfs_file
   nfsd_file_acquire_opened
    nfsd_file_do_acquire // 返回 nfs_ok
     nfsd_open_verified
      __nfsd_open
       nfsd_open_break_lease
        break_lease // 冲突锁已从 flc_lease 上移除，返回0



