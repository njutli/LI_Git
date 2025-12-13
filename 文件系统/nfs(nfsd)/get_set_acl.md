# 背景
[Linux ACL （访问控制列表）权限管理举例详解](https://blog.csdn.net/Zheng__Huang/article/details/107743768)
- mode bits：简单、快、所有系统/工具都理解，适合绝大多数场景
- ACL：当你需要“给某个用户单独开绿灯/拉黑”而又不想改 owner/group 或创建新组时，它非常实用

```
// acl权限检查
inode_permission
 do_inode_permission
  generic_permission
   acl_permission_check
    check_acl
	 get_inode_acl // 获取acl
	 posix_acl_permission // 检查权限
	  // 如果acl中有一条记录是普通用户 case ACL_USER
	  // make_vfsuid 获取acl中的用户vfsuid
	  // 与当前用户的uid current_fsuid() 比较
	  // 相同的话直接跳转检查这条记录对这个用户的权限规定，运行访问返回0，否则返回 -EACCES

static int acl_permission_check(struct mnt_idmap *idmap,
                                struct inode *inode, int mask)
{
        unsigned int mode = inode->i_mode;
        vfsuid_t vfsuid;

        if (!((mask & 7) * 0111 & ~mode)) {
                if (no_acl_inode(inode))
                        return 0;
                if (!IS_POSIXACL(inode))
                        return 0;
        }
		// 如果这个文件对 所有人 都已经满足请求权限（例如要读而 mode 至少是 ?r? ?r? ?r?），并且 inode 上也没有需要看的 ACL，那就直接允许。

        /* Are we the owner? If so, ACL's don't matter */
		// 获取文件owner对应的 vfsuid
        vfsuid = i_uid_into_vfsuid(idmap, inode);
        // 将文件的owner的 vfsuid 与当前用户的 fsuid 比较，确认当前用户是否是文件所有者
		if (likely(vfsuid_eq_kuid(vfsuid, current_fsuid()))) {
		// 如果是文件所有者，不考虑acl，只考虑mode
                mask &= 7;
                mode >>= 6;
                return (mask & ~mode) ? -EACCES : 0;
        }

        /* Do we have ACL's? */
        if (IS_POSIXACL(inode) && (mode & S_IRWXG)) {
                int error = check_acl(idmap, inode, mask);
                if (error != -EAGAIN)
                        return error;
        }

        /* Only RWX matters for group/other mode bits */
        mask &= 7;

        /*
         * Are the group permissions different from
         * the other permissions in the bits we care
         * about? Need to check group ownership if so.
         */
        if (mask & (mode ^ (mode >> 3))) {
				// 当前用户属于文件的group，取出group用户权限
                vfsgid_t vfsgid = i_gid_into_vfsgid(idmap, inode);
                if (vfsgid_in_group_p(vfsgid))
                        mode >>= 3;
        }

        /* Bits in 'mode' clear that we require? */
        return (mask & ~mode) ? -EACCES : 0;
}

```

# 现象
`给普通用户新增一个r权限的deny条目，影响了owner`
# 操作步骤
```
mkfs.ext4 -F /dev/sdb
mount /dev/sdb /mnt/sdb
echo "/mnt *(rw,no_root_squash,fsid=0)" > /etc/exports
echo "/mnt/sdb *(rw,no_root_squash,fsid=1)" >> /etc/exports
systemctl restart nfs-server

touch /mnt/sdb/a
setfacl -m u:1000:rwx /mnt/sdb/a

mount -t nfs -o rw,vers=4.0 192.168.6.252:/sdb /mnt/sdbb

[root@fedora ~]# nfs4_getfacl /mnt/sdbb/a

# file: /mnt/sdbb/a
A::OWNER@:rwatTcCy
A::GROUP@:rtcy
A::EVERYONE@:rtcy
[root@fedora ~]# nfs4_setfacl -a D::1000:r /mnt/sdbb/a
[root@fedora ~]# nfs4_getfacl /mnt/sdbb/a

# file: /mnt/sdbb/a
D::OWNER@:r
A::OWNER@:watTcCy
D::1000:r
A::1000:tcy
A::GROUP@:rtcy
A::EVERYONE@:rtcy
[root@fedora ~]#
```

# 系统调用
## 1. nfs4_getfacl
[2025-12-09 09:35:21]  getxattr("/mnt/sdbb/b", "system.nfs4_acl", "\0\0\0\3\0\0\0\0\0\0\0\0\0\26\1\207\0\0\0\6OWNER@\0\0\0\0\0", 80) = 80

## 2. nfs4_setfacl
[2025-12-09 09:35:44]  getxattr("/mnt/sdbb/b", "system.nfs4_acl", "\0\0\0\3\0\0\0\0\0\0\0\0\0\26\1\207\0\0\0\6OWNER@\0\0\0\0\0", 80) = 80
[2025-12-09 09:35:44]  setxattr("/mnt/sdbb/b", "system.nfs4_acl", "\0\0\0\4\0\0\0\1\0\0\0\0\0\0\0\1\0\0\0\0041000\0\0\0\0\0\0\0", 100, XATTR_REPLACE) = 0

## 3. nfs4_getfacl
[2025-12-09 09:35:57]  getxattr("/mnt/sdbb/b", "system.nfs4_acl", "\0\0\0\6\0\0\0\1\0\0\0\0\0\0\0\1\0\0\0\6OWNER@\0\0\0\0\0", 144) = 144

# 代码流程
## 1. nfs4_getfacl
### 1.1 客户端发起请求
```
path_getxattr
 getxattr
  vfs_getxattr
   __vfs_getxattr
    nfs4_xattr_get_nfs4_acl // nfs4_xattr_handlers --> nfs4_xattr_nfs4_acl_handler(XATTR_NAME_NFSV4_ACL -- "system.nfs4_acl")
	 nfs4_proc_get_acl
	  nfs4_get_acl_uncached
	   __nfs4_get_acl_uncached
	    kmalloc_array // 分配保存结果的 pages
		args.acl_pages // 将 pages 保存在 acl_pages 中
	    nfs4_call_sync // 远程调用
		nfs4_write_cached_acl // 从缓存 pages 里拷出数据
		 _copy_from_pages // 拷贝数据
		 nfs4_set_cached_acl // 保存到 nfsi->nfs4_acl 中

call_encode
 rpc_xdr_encode
  rpcauth_wrap_req
   rpcauth_wrap_req_encode // ops->crwrap_req
    nfs4_xdr_enc_getacl // NFSPROC4_CLNT_GETACL
	 rpc_prepare_reply_pages
	  xdr_inline_pages
	   // 将 pages 信息保存在 req->rq_rcv_buf 中
 xprt_request_enqueue_receive
  xprt_request_prepare
   xs_stream_prepare_request // xprt->ops->prepare_request
    xdr_alloc_bvec
	 // 分配 bio_vec ，关联 page
 memcpy // 将 req->rq_rcv_buf 中关于 pages 的信息拷贝到 req->rq_private_buf 中

```

### 1.2 服务端响应请求
```
// OP_GETATTR
nfsd4_decode_getattr
 nfsd4_decode_bitmap4
  // 解析获取 getattr->ga_bmval ，对应客户端 nfs4_xdr_enc_getacl 编码的 nfs4_acl_bitmap

nfsd4_getattr
 // 服务端只是通过 nfsd_suppattrs mask一下？

nfsd4_encode_getattr
 nfsd4_encode_fattr4
  nfsd4_get_nfs4_acl // FATTR4_WORD0_ACL
   get_acl // 从底层文件系统获取 posix_acl 格式的acl
    ext4_get_acl // inode->i_op->get_acl
	 ext4_acl_from_disk
   kmalloc // 分配 nfs4_acl ，数量是 posix_acl 的两倍，按每个 posix_acl 对应一个(deny,allow)对的最差情况考虑
   _posix_to_nfsv4_one
    summarize_posix_acl // 将底层文件系统的 posix_acl 转换成 posix_acl_summary
	// 以 nfs4_acl 中 nfs4_ace 的形式保存 acl 信息 --> acl->aces
  // FATTR4_WORD0_ACL 将 acl->aces 中的信息编码到缓存中
  *p++ = cpu_to_be32(ace->type);
  *p++ = cpu_to_be32(ace->flag);
  *p++ = cpu_to_be32(ace->access_mask & NFS4_ACE_MASK_ALL);
  nfsd4_encode_aclname
   nfs4_acl_write_who
    // 写入 s2t_map[i].string

```

### 1.3 客户端处理服务端返回的数据

> 数据是怎么接收的，接收的数据是怎么填充到page中的

#### 1.3.1 网络中断触发 transport->recv_worker 的第一次运行
```
@x[
    xs_data_ready+1
    tcp_rcv_established+1605
    tcp_v4_do_rcv+463
    tcp_v4_rcv+4203
    ip_protocol_deliver_rcu+67
    ip_local_deliver_finish+134
    ip_local_deliver+95
    ip_sublist_rcv_finish+199
    ip_list_rcv_finish.constprop.0+440
    ip_list_rcv+342
    __netif_receive_skb_list_core+699
    netif_receive_skb_list_internal+499
    napi_complete_done+109
    virtnet_poll+479
    napi_poll+201
    net_rx_action+184
    __softirqentry_text_start+370
    asm_call_irq_on_stack+18
    do_softirq_own_stack+91
    irq_exit_rcu+264
    common_interrupt+171
    asm_common_interrupt+30
    __cpuidle_text_start+19
    default_idle_call+104
    cpuidle_idle_call+322
    do_idle+141
    cpu_startup_entry+25
    secondary_startup_64_no_verify+195
, swapper/7]: 2
```

#### 1.3.2 recv_worker 会一直运行到网络关闭
```
xs_stream_data_receive_workfn
 xs_stream_data_receive
  xs_read_stream
   xs_read_stream_reply
    xs_read_stream_request
	 xs_read_xdr_buf
	  xs_read_bvec
	   iov_iter_bvec // 关联 msg->msg_iter 与 bvec --> msg->msg_iter->bvec
	   xs_sock_recvmsg
	    sock_recvmsg
		 sock_recvmsg_nosec
		  inet_recvmsg
		   tcp_recvmsg
		    skb_copy_datagram_msg
			 skb_copy_datagram_iter
			  __skb_datagram_iter
			   simple_copy_to_iter // 拷贝网络数据到之前分配的本地 pages 缓存中
 1) 停止
 kernel_sock_shutdown
 2) 继续
 xs_poll_check_readable
  queue_work // transport->recv_worker
```

## 2. nfs4_getfacl
### 2.1 客户端发起请求
```
path_setxattr
 setxattr
  vfs_setxattr
   __vfs_setxattr_locked
    __vfs_setxattr_noperm
	 __vfs_setxattr
	  nfs4_xattr_set_nfs4_acl
	   nfs4_proc_set_acl
	    __nfs4_proc_set_acl
		 nfs4_buf_to_pages_noslab
		  // 分配 pages ，将用户态传递的数据保存在pages中并用 acl_pages 保存 pages 指针数组地址

call_encode
 rpc_xdr_encode
  rpcauth_wrap_req
   rpcauth_wrap_req_encode // ops->crwrap_req
    nfs4_xdr_enc_setacl // NFSPROC4_CLNT_SETACL
	 encode_setacl // 操作码 OP_SETATTR； map FATTR4_WORD0_ACL； acl_pages 保存到 xdr 中

```

### 2.2 服务端响应请求
```
nfsd4_decode_setattr
 nfsd4_decode_fattr4
  nfsd4_decode_bitmap4 // 获取bitmap FATTR4_WORD0_ACL
  nfsd4_decode_acl // 获取用户传递的 acl 以 nfs4_acl 的格式保存在 setattr->sa_acl 中


nfsd4_setattr
 nfsd4_acl_to_attr
  nfs4_acl_nfsv4_to_posix
   nfs4_acl_nfsv4_to_posix
    process_one_v4_ace
	 // case ACL_USER:
	 // 对一个普通用户设置deny bit时也会对owner设置

```


