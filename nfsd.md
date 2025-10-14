## 远程调用
```
nfs4_xdr_enc_xxx
nfs4_xdr_dec_xxx

/proc/sys/sunrpc/nfs_debug
/proc/sys/sunrpc/nfsd_debug

tcpdump -i any -w file.cap


/********************* 挂载 *********************/

// OP_SEQUENCE
用于标识特定session的请求？
返回状态信息，更新 seqid
The SEQUENCE operation is used by the server to implement session request control and the reply cache semantics.
https://www.rfc-editor.org/rfc/rfc5661#page-553
如果一个COMPOUND请求有SEQUENCE操作，则SEQUENCE操作一定是请求的第一个操作
除了 SEQUENCE, BIND_CONN_TO_SESSION, EXCHANGE_ID, CREATE_SESSION, and DESTROY_SESSION 其他任何操作不能是请求的第一个操作


// NFSPROC4_CLNT_EXCHANGE_ID
// OP_EXCHANGE_ID
server端为client端创建 nfs4_client，分配 clientid，确认状态保护模式，返回server信息

通过 rpc_call_sync 调用 rpc_run_task， callback_ops 为默认的 rpc_default_ops

client端
do_mount
...
nfs4_proc_exchange_id
 _nfs4_proc_exchange_id
  nfs4_run_exchange_id
   ...
   nfs4_xdr_enc_exchange_id // 发送信息编码
   nfs4_xdr_dec_exchange_id // 解码接收信息

server端
nfsd4_exchange_id
 create_client // 根据 exid->clname 和 exid->verifier 创建新的 nfs4_client
               // clname:current->nsproxy->uts_ns->name UTS namespace名
  gen_clid
   clp->cl_clientid.cl_boot = (u32)nn->boot_time; // 记录 server nfsd 服务启动时间
   clp->cl_clientid.cl_id = nn->clientid_counter++; // server 为 client 分配 clientid
 find_confirmed_client_by_name // 根据 exid->clname(即client端args->client->cl_owner_id) 查找 nfs4_client
  find_clp_in_name_tree // 在 nn->conf_name_tree 树上查找匹配的 nfs4_client
 add_to_unconfirmed // 添加到 nn->unconf_name_tree 树上，nn->unconf_id_hashtbl[idhashval] 链表中
 exid->clientid.cl_boot = conf->cl_clientid.cl_boot; // 将 clientid 信息传递给 nfsd4_exchange_id
 exid->clientid.cl_id = conf->cl_clientid.cl_id;
 exid->seqid = conf->cl_cs_slot.sl_seqid + 1 // cs_slot->sl_seqid 在 create_session 时++

调用结果
client端从server端获取到的信息：
struct nfs41_exchange_id_res *res // 客户端
struct nfsd4_exchange_id *exid // 服务端
// 客户端标识
res->clientid <-- exid->clientid // clientid 用于标识一个客户端
res->seqid <-- exid->seqid // session id + seq id 可以唯一标识一个请求
res->flags <-- exid->flags
res->state_protect.how <-- exid->spa_how // 状态保护模式
res->state_protect.enforce <-- exid->spo_must_enforce
res->state_protect.allow <-- exid->spo_must_allow
// 服务端信息
res->server_owner->minor_id
res->server_owner->major_id
res->server_owner->major_id_sz
res->server_scope->server_scope_sz
res->impl_id->domain
res->impl_id->name
res->impl_id->date.nseconds



// NFSPROC4_CLNT_CREATE_SESSION
// OP_CREATE_SESSION
server创建 nfsd4_session，为新的session分配 sequence，关联 nfs4_client；server根据自身情况设置 max_reqs 等参数返回给client

通过 rpc_call_sync 调用 rpc_run_task， callback_ops 为默认的 rpc_default_ops

client端
// mount 命令创建后台进程进行 create_session
nfs4_proc_create_session
 _nfs4_proc_create_session
  ...
  nfs4_xdr_enc_create_session
   encode_create_session // nfs_opnum4 OP_CREATE_SESSION
  nfs4_xdr_dec_create_session


server端
nfsd4_create_session
 check_forechannel_attrs // 前向通道校验设置，校验client传递的最小值，根据server情况设置最大值
  cr_ses->fore_channel->maxreq_sz = min_t(u32, ca->maxreq_sz, maxrpc)
  cr_ses->fore_channel->maxresp_sz
  cr_ses->fore_channel->maxops
  cr_ses->fore_channel->maxresp_cached
  cr_ses->fore_channel->maxreqs
 check_backchannel_attrs // 反向通道校验
 alloc_session
  kzalloc // 分配 nfsd4_session 包括 (struct nfsd4_slot *se_slots[]) 个数为 maxreqs
  kzalloc // 分配 nfsd4_slot 包括 (char sl_data[])
  memcpy // 初始化 nfsd4_session->se_fchannel
  memcpy // 初始化 nfsd4_session->se_bchannel
 alloc_conn_from_crses // 分配 nfsd4_conn
 find_unconfirmed_client // 在 nn->unconf_id_hashtbl 中查找指定clientid的client
 find_confirmed_client // 在 nn->conf_id_hashtbl 中查找指定clientid的client
 -----------------
 1) 找到 confirmed client
 check_slot_seqid // 校验 seqid -- cr_ses->seqid == cs_slot->sl_seqid + 1
 2) 找到 unconfirmed client
 check_slot_seqid // 校验 seqid
 find_confirmed_client_by_name // 再次查找client
 move_to_confirmed // 将client从 unconfirmed 链表移到 confirmed 链表
 -----------------
 cr_ses->flags &= ~SESSION4_PERSIST;
 cr_ses->flags &= ~SESSION4_RDMA;
 init_session
  new->se_client = clp // 关联 nfsd4_session 和 nfs4_client
  gen_sessionid // 初始化 ses->se_sessionid.data
   sid->clientid = clp->cl_clientid;
   sid->sequence = current_sessionid++;
  list_add(&new->se_hash, &nn->sessionid_hashtbl[idx])
  list_add(&new->se_perclnt, &clp->cl_sessions);
 memcpy // 初始化 cr_ses->sessionid.data
 nfsd4_cache_create_session // 初始化 nfsd4_clid_slot->sl_cr_ses
 cs_slot->sl_seqid++
 cr_ses->seqid = cs_slot->sl_seqid // 更新 seqid
 nfsd4_init_conn
  nfsd4_hash_conn
   __nfsd4_hash_conn // nfsd4_conn 通过 conn->cn_persession 加入 nfsd4_session 的 ses->se_conns 链表

一个nfsd4_session可能对应多个nfsd4_conn,因为NFS4.1允许一个会话使用多个连接进行传输以提高带宽和冗余。

调用结果
struct nfs41_create_session_res *res
res->sessionid <-- sess->sessionid.data
res->seqid <-- sess->seqid
res->flags <-- sess->flags
res->fc_attrs
 attrs->max_rqst_sz <-- sess->fore_channel.maxreq_sz
 attrs->max_resp_sz <-- sess->fore_channel.maxresp_sz
 attrs->max_resp_sz_cached <-- sess->fore_channel.maxresp_cached
 attrs->max_ops <-- sess->fore_channel.maxops
 attrs->max_reqs <-- sess->fore_channel.maxreqs
res->bc_attrs





// NFSPROC4_CLNT_RECLAIM_COMPLETE(client) == OP_SEQUENCE+OP_RECLAIM_COMPLETE(server)
// OP_RECLAIM_COMPLETE
在server重启或client挂载时发送，用于声明对锁的占用状态？并没有实际的数据传递
通过 nfs41_proc_reclaim_complete --> nfs4_call_sync_custom 调用 rpc_run_task， callback_ops 为 nfs4_reclaim_complete_call_ops

SEQ4_STATUS_RESTART_RECLAIM_NEEDED
      When set, indicates that due to server restart, the client must
      reclaim locking state.  Until the client sends a global
      RECLAIM_COMPLETE (Section 18.51), every SEQUENCE operation will
      return SEQ4_STATUS_RESTART_RECLAIM_NEEDED

client端
nfs41_proc_reclaim_complete
 calldata->arg.one_fs = 0; // 全局的 RECLAIM_COMPLETE
 ...
 nfs4_xdr_enc_reclaim_complete
  encode_reclaim_complete
   encode_op_hdr // OP_RECLAIM_COMPLETE
 nfs4_xdr_dec_reclaim_complete
  decode_reclaim_complete
   decode_op_hdr // 获取返回值

   o  When rca_one_fs is FALSE, a global RECLAIM_COMPLETE is being done.
      This indicates that recovery of all locks that the client held on
      the previous server instance have been completed.

   o  When rca_one_fs is TRUE, a file system-specific RECLAIM_COMPLETE
      is being done.  This indicates that recovery of locks for a single
      fs (the one designated by the current filehandle) due to a file
      system transition have been completed.  Presence of a current
      filehandle is only required when rca_one_fs is set to TRUE.

server端
nfsd4_decode_reclaim_complete
 rc->rca_one_fs = be32_to_cpup(p++); // 获取关键参数 rca_one_fs

nfsd4_reclaim_complete
 1) rca_one_fs == true
  根据 cstate->current_fh 是否存在返回 nfserr_nofilehandle 或 nfs_ok
 2) rca_one_fs == false
  test_and_set_bit // 检测当前client的 NFSD4_CLIENT_RECLAIM_COMPLETE 标记
                   // 若已设置，则返回 nfserr_complete_already
  is_client_expired // 若client已过期，则返回 nfserr_stale_clientid
  // 原来没有 NFSD4_CLIENT_RECLAIM_COMPLETE 标记，成功设置
  nfsd4_client_record_create // 创建记录
  inc_reclaim_complete // 增加 reclaim_complete 记录

@m[
    nfsd4_umh_cltrack_upcall+1
    nfsd4_umh_cltrack_create+359
    nfsd4_client_record_create+159
    nfsd4_reclaim_complete+264
    nfsd4_proc_compound+1777
    nfsd_dispatch+555
    svc_process+3173
    nfsd+506
    kthread+516
    ret_from_fork+31
, nfsd]: 1

nfsd4_reclaim_complete
 nfsd4_client_record_create
  nfsd4_umh_cltrack_create
   nfsd4_umh_cltrack_upcall


// NFSPROC4_CLNT_SECINFO_NO_NAME(client) = OP_SEQUENCE+OP_PUTROOTFH+OP_SECINFO_NO_NAME(server)
// OP_PUTROOTFH
将cstate->current_fh设置成server端导出的根目录(fsid=0)对应的句柄
// OP_SECINFO_NO_NAME
client端
nfs41_proc_secinfo_no_name
 _nfs41_proc_secinfo_no_name
 ...
 nfs4_xdr_enc_secinfo_no_name
  encode_putrootfh
  encode_secinfo_no_name
   // 传递参数 nfs41_secinfo_no_name_args args.style = SECINFO_STYLE_CURRENT_FH
 nfs4_xdr_dec_secinfo_no_name
  decode_putrootfh
  decode_secinfo_no_name
   // 解析安全信息

server端
nfsd4_putrootfh // 不接收client任何参数，不给client返回任何数据
 fh_put // 清除 cstate->current_fh
 exp_pseudoroot // 重新设置 cstate->current_fh
  rqst_find_fsidzero_export
   mk_fsid // 创建一个新的 fsid (dev=0/ino=0/fsid=0)
   rqst_exp_find
    exp_find
	 exp_find_key
	  svc_expkey_lookup // 根据 client 和 fsid 计算的hash查找 svc_expkey
						// 该 svc_expkey 来源于服务启动时用户态工具通过sunrpc接口写导出目录设置
	 exp_get_by_name
	  // 将 svc_expkey 的 ek_path 传递给 svc_export 的 ex_path
  fh_compose // 设置 cstate->current_fh
   // fhp->fh_dentry ex_path 对应的 dentry
   // fhp->fh_export svc_export
   // fhp->fh_handle

// root filehandle 来源
nfsd实现了名为"nfsd.fh"的 cache_detail svc_expkey_cache_template
通过向sunrpc接口 /proc/net/rpc/nfsd.fh/channel 写入导出目录信息，设置 nfsd 模块中的导出目录
@zz[
    expkey_parse+1
    cache_do_downcall+105
    cache_write.isra.0+368
    cache_write_procfs+110
    proc_reg_write+272
    vfs_write+281
    ksys_write+205
    __x64_sys_write+70
    do_syscall_64+69
    entry_SYSCALL_64_after_hwframe+97
, rpc.mountd]: 1
[2024-04-13 15:05:48]  [  194.597276] found domain *
[2024-04-13 15:05:48]  [  194.599468] found fsidtype 1
[2024-04-13 15:05:48]  [  194.599927] found fsid length 4
[2024-04-13 15:05:48]  [  194.600516] Path seems to be </mnt>
[2024-04-13 15:05:48]  [  194.601264] Found the path /mnt
@n[
    svc_export_parse+1
    cache_do_downcall+105
    cache_write.isra.0+368
    cache_write_procfs+110
    proc_reg_write+272
    vfs_write+281
    ksys_write+205
    __x64_sys_write+70
    do_syscall_64+69
    entry_SYSCALL_64_after_hwframe+97
, rpc.mountd]: 2


nfsd4_secinfo_no_name // 获取 sin_style 参数 NFS4_SECINFO_STYLE4_CURRENT_FH
 exp_get // get cstate->current_fh.fh_export，用于给客户端返回安全信息

nfsd4_decode_secinfo_no_name
 // 获取 sin_style 参数

nfsd4_encode_secinfo_no_name
 nfsd4_do_encode_secinfo

当服务器通过svc接口创建一个新的后台进程时，会创建一个新的nfsd4_compound_state实例，这个实例中的 current_fh 是本次请求要操作的文件句柄

当服务器收到 OP_PUTROOTFH 请求时，会将实例中的 current_fh 设置为fsid为0的文件系统根目录句柄(初始化阶段用户态通过向sunrpc接口 /proc/pid_xxx/net/rpc/nfsd.fh/channel 写入导出目录信息来生成该句柄，保存在sunrpc的缓存中)

当服务器收到 OP_PUTFH 请求时，会将实例中的 current_fh 设置为客户端指定的文件句柄






// NFSPROC4_CLNT_LOOKUP_ROOT(client) = OP_SEQUENCE+OP_PUTROOTFH+OP_GETFH+OP_GETATTR
// 获取 root filehandle
client端
nfs4_lookup_root
 _nfs4_lookup_root
 ...
 nfs4_xdr_enc_lookup_root
  encode_putrootfh
  encode_getfh
  encode_getfattr
   // 传递给服务端一个 xdr_encode_bitmap4，表明想要获取的属性
 nfs4_xdr_dec_lookup_root
  decode_putrootfh
  decode_getfh
  decode_getfattr_label

server端
// OP_PUTROOTFH
在客户端发送 OP_PUTROOTFH 时，
内核接收到请求，查找 与 FSID_NUM 类型的 fsid(0, 0) 对应的 svc_export；
找不到时新分配 svc_export ，但不设置 CACHE_VALID，调用 upcall 通知用户态处理；
用户态通过 cache_request 回调读取文件信息，打开对应的文件并将相关信息保存到 svc_export 中；
（这里读的是 fsid(0, 0) ，如果没有指定fsid=0的导出点，则将打开根目录；如果指定了fsid=0的目录，则打开对应目录）；
在用户态处理的过程中，内核态阻塞等待，之后通过 fh_compose() 函数将相关信息编码
// 与 FSID_NUM 类型的 fsid(0, 0) 对应
nfsd4_putrootfh
 exp_pseudoroot
  rqst_find_fsidzero_export
   mk_fsid // FSID_NUM 0 0 0 NULL
   rqst_exp_find
    exp_find
	 exp_find_key // cache_detail 为 nn->svc_expkey_cache ，即 svc_expkey_cache_template 
	  memcpy // key.ek_fsid <-- fsidv
	  svc_expkey_lookup
	   svc_expkey_hash
	   sunrpc_cache_lookup_rcu
	    sunrpc_cache_find_rcu // 在指定 cache_detail 中查找 cache_head(svc_expkey)
		sunrpc_cache_add_entry // 没找到则分配并添加
		 expkey_alloc // detail->alloc()
		 cache_init
		  h->expiry_time = now + CACHE_NEW_EXPIRY
		  h->last_refresh = now // now = detail->flush_time + 1
	  cache_check
	   cache_is_valid // 返回 -EAGAIN， CACHE_VALID 未设置
	   expkey_upcall // detail->cache_upcall
	    sunrpc_cache_pipe_upcall
		 cache_pipe_upcall
	   cache_defer_req
	    cache_wait_req // 阻塞等待，超时时长为 req->thread_wait (1s或5s)
	   cache_is_valid // 返回 0， CACHE_VALID 已设置
	 exp_get_by_name
	  svc_export_lookup // 查找或分配 cache_head(svc_export)
	  cache_check
 fh_compose


// 用户态获取待查找文件信息
cache_read_procfs
 cache_read
  cache_request
   expkey_request // detail->cache_request
   // 将文件信息转换为字符串给用户态处理


// 将处理结果传递给内核
cache_write_procfs
 cache_write
  cache_downcall
   cache_do_downcall
    svc_export_parse // cd->cache_parse
	 svc_export_lookup
	 svc_export_update
	  sunrpc_cache_update
	   cache_fresh_locked
	   // 设置 CACHE_VALID







// OP_GETFH
nfsd4_getfh
 // 获取 cstate->current_fh
nfsd4_encode_getfh
 // 给客户端返回 fhp->fh_handle.fh_base

// OP_GETATTR
nfsd4_getattr
 // 获取 nfsd 支持的属性保存在 nfsd4_getattr 中

nfsd4_encode_fattr
 // 根据客户端的bitmap返回客户端想要获取的属性




// NFSPROC4_CLNT_SERVER_CAPS(client) = OP_SEQUENCE+OP_PUTFH+OP_GETATTR
// 获取指定 filehandle 的属性

client端
nfs4_server_capabilities
 _nfs4_server_capabilities
  ...
  nfs4_xdr_enc_server_caps
   encode_sequence
   encode_putfh
    // 传递给服务端一个 filehandle ，用于获取该文件的属性
   encode_getattr
  nfs4_xdr_dec_server_caps
   decode_sequence
   decode_putfh
   decode_server_caps
    // 解析文件属性

server端
nfsd4_putfh
 fh_put // 清除当前的 current_fh
 memcpy // 将 current_fh 设置成客户端传递过来的文件
 fh_verify // 校验 current_fh





// NFSPROC4_CLNT_FSINFO(client) = OP_SEQUENCE+OP_PUTFH+OP_GETATTR
// 获取指定 filehandle 的属性
client端
nfs4_do_fsinfo
 _nfs4_do_fsinfo
 ...
  nfs4_xdr_enc_fsinfo
   encode_sequence
   encode_putfh
   encode_getattr
  nfs4_xdr_dec_fsinfo
   decode_sequence
   decode_putfh
   decode_fsinfo

server端
与 NFSPROC4_CLNT_SERVER_CAPS 类似，返回文件不同的属性





// NFSPROC4_CLNT_PATHCONF(client) = OP_SEQUENCE+OP_PUTFH+OP_GETATTR
// 获取指定 filehandle 的属性
client端
nfs4_proc_pathconf
 _nfs4_proc_pathconf
 ...
  nfs4_xdr_enc_pathconf
   encode_sequence
   encode_putfh
   encode_getattr
  nfs4_xdr_dec_pathconf
   decode_sequence
   decode_putfh
   decode_pathconf

server端
与 NFSPROC4_CLNT_SERVER_CAPS 类似，返回文件不同的属性




// NFSPROC4_CLNT_GETATTR(client) = OP_SEQUENCE+OP_PUTFH+OP_GETATTR
client端
nfs4_proc_getattr
 _nfs4_proc_getattr
 ...
  nfs4_xdr_enc_getattr
   encode_sequence
   encode_putfh
   encode_getattr
  nfs4_xdr_dec_getattr
   decode_sequence
   decode_putfh
   decode_getfattr_label

server端
与 NFSPROC4_CLNT_SERVER_CAPS 类似，返回文件不同的属性





// NFSPROC4_CLNT_ACCESS(client) = OP_SEQUENCE+OP_PUTFH+OP_ACCESS+OP_GETATTR
client端
nfs4_proc_access
 _nfs4_proc_access // nfs_access_entry.mask 表明想要获取的权限
  ...
  nfs4_xdr_enc_access
   encode_sequence
   encode_putfh
   encode_access
    // args->access 即 nfs_access_entry.mask
   encode_getattr
  nfs4_xdr_dec_access
   decode_sequence
   decode_putfh
   decode_access
    // *supported = supp;
	// *access = acc;
   decode_getfattr

server端
// OP_ACCESS
nfsd4_decode_access
 access->ac_req_access = be32_to_cpup(p++);
 // 将客户端传递过来的 access 保存在 ac_req_access 中

nfsd4_access
 access->ac_resp_access = access->ac_req_access
 nfsd_access
  nfsd_permission // 根据客户端请求的权限校验是否允许对应的操作
   *access = result // 经校验后当前客户端可获取的权限
   *supported = sresult // 当前服务端能支持的权限






// NFSPROC4_CLNT_LOOKUP(client) = OP_SEQUENCE+OP_PUTFH+OP_LOOKUP+OP_GETFH+OP_GETATTR
// 在指定目录下查找指定文件
client端
nfs4_proc_lookup
 nfs4_proc_lookup_common
  _nfs4_proc_lookup
   ...
   nfs4_xdr_enc_lookup
    encode_sequence
	encode_putfh // 设置服务端的 current_fh 为目录文件
	encode_lookup // 查找目录下的目标文件
	 encode_string // encode 待查文件的文件名的长度和文件名
	encode_getfh // 获取目标文件的 filehandle
	encode_getfattr // 获取目标文件的属性
   nfs4_xdr_dec_lookup
    decode_sequence
	decode_putfh
	decode_lookup
	decode_getfh
	decode_getfattr_label

server端
// OP_LOOKUP
nfsd4_decode_lookup
 lookup->lo_len
 lookup->lo_name
 // 获取待查文件的文件名

// 在 current_fh 下查找 lo_name 对应的文件，并将 current_fh 设置为查找的文件
nfsd4_lookup
 nfsd_lookup
  fh_verify // 校验当前 current_fh 即目录的 filehandle
   nfsd_set_fh_dentry
    rqst_exp_find
  nfsd_lookup_dentry // 查找目标文件的 dentry
  check_nfsd_access // 校验访问权限
  fh_compose // 将 current_fh 设置为查找的文件






/********************* 查看目录 *********************/
// 根据路径找 dentry ，根据 dentry 从服务端 readdir

nfs4_proc_getattr
 _nfs4_proc_getattr


nfs4_proc_access
 _nfs4_proc_access


// NFSPROC4_CLNT_READDIR(client) = OP_SEQUENCE+OP_PUTFH+OP_READDIR
client端
nfs4_proc_readdir
 _nfs4_proc_readdir
  // .fh = NFS_FH(dir) 用于 OP_PUTFH 操作
 ...
  nfs4_xdr_enc_readdir
   encode_sequence
   encode_putfh
   encode_readdir
    // xdr: OP_READDIR readdir->cookie readdir->verifier
    // dircount readdir->count attrlen attrs[i]
dir (CURRENT_FH): 表示要读取的目录对应的文件句柄
cookie: 指示从哪个位置开始读取目录项,通常第一次使用 0 作为 cookie 值
cookieverf: 用于检查目录是否被修改过的verifier
dircount: 期望返回的最大字节数
maxcount: 单个目录项名称的最大长度
   rpc_prepare_reply_pages
    // args->pages, args->pgbase, args->count
  nfs4_xdr_dec_readdir
   decode_sequence
   decode_putfh
   decode_readdir


server端
nfsd4_decode_readdir
 readdir->rd_cookie ———— readdir->cookie
 readdir->rd_verf.data ———— readdir->verifier
 readdir->rd_dircount ———— dircount
 readdir->rd_maxcount ———— readdir->count
 readdir->rd_bmval ———— readdir->count attrlen attrs[i]

nfsd4_readdir
 readdir->rd_bmval[0/1/2]
 readdir->rd_rqstp
 readdir->rd_fhp = &cstate->current_fh

nfsd4_encode_readdir
 nfsd_readdir // nfsd4_encode_dirent
  nfsd_open // server端内部打开目标文件
   __nfsd_open
  vfs_llseek
  nfsd_buffered_readdir
   iterate_dir // 读取server端本地文件夹内容
   nfsd4_encode_dirent // server端本地文件夹的内容保存到xdr中




/********************* 读文件 *********************/
挂载点为 /mnt/sdb

// open testfile
// read testfile
// close testfile
cat /mnt/sdb/testfile

// access testdir 确认 testdir 的访问权限
// lookup testdir 查找 testdir1 分配新的 inode
// access testdir/testdir1 确认 testdir/testdir1 的访问权限
// lookup testdir/testdir1 查找 testdir/testdir1/testfile 分配新的 inode
// access testdir/testdir1/testfile 确认 testdir/testdir1/testfile 的访问权限
// open testfile open 操作会在给定目录的 filehandle 下做 lookup 操作
// read testfile
// close testfile
cat /mnt/sdb/testdir/testdir1/testfile

nfs4_proc_access
 _nfs4_proc_access

nfs4_proc_lookup
 nfs4_proc_lookup_common
  _nfs4_proc_lookup

<---------------- 打开文件 ---------------->
// NFSPROC4_CLNT_OPEN(client) = OP_SEQUENCE+OP_PUTFH+OP_OPEN+OP_GETFH+OP_ACCESS+OP_GETATTR
client端
nfs4_do_open
 _nfs4_do_open
  nfs4_get_state_owner
   nfs4_alloc_state_owner
    sp->so_seqid.owner_id = ida_simple_get // 客户端分配一个未使用的ID，传递给服务器，标识当前客户端的文件打开实例
  nfs4_opendata_alloc // 分配 nfs4_opendata
   // nfs4_opendata->o_arg 初始化 nfs_openargs
   nfs4_map_atomic_open_share // p->o_arg.share_access 决定是否需要获取 delegation
  _nfs4_open_and_get_state
   _nfs4_proc_open
    nfs4_run_open_task




nfs4_xdr_enc_open
 encode_sequence
 encode_putfh // args->fh 目标文件所在目录的 filehandle ？
 encode_open
  encode_op_hdr
  encode_openhdr // open操作的头 seqid/share_access/clientid...
  encode_opentype // 文件打开类型 NFS4_OPEN_NOCREATE/NFS4_OPEN_CREATE
  encode_claim_xxx // claim 类型
NFS4_OPEN_CLAIM_NULL - 表示客户端没有任何以前的状态要重新获取,它只是想新打开一个文件。这通常用于首次打开文件。
NFS4_OPEN_CLAIM_PREVIOUS - 客户端声明它想重新建立之前由同一个 open_owner 打开的文件的状态。当客户端异常重启后,需要重新获取之前的 open 状态时使用该选项。
NFS4_OPEN_CLAIM_DELEGATE_CUR - 客户端声明它持有指定文件的委托(delegation),并想一直保留该委托。用于客户端重启后重建委托状态。
NFS4_OPEN_CLAIM_DELEGATE_PREV - 客户端声明它以前持有指定文件的委托,但委托已被回收。客户端想试着重新获得该委托。
NFS4_OPEN_CLAIM_FH - 客户端声明它只知道文件的文件句柄,但不知道之前是否持有任何状态。服务器必须检查这个文件句柄是否仍然指向服务器上的一个有效对象。
NFS4_OPEN_CLAIM_DELEG_CUR_FH - 类似于 NFS4_OPEN_CLAIM_DELEGATE_CUR,但客户端只知道文件的文件句柄。
NFS4_OPEN_CLAIM_DELEG_PREV_FH - 类似于 NFS4_OPEN_CLAIM_DELEGATE_PREV,但客户端只知道文件的文件句柄。
 encode_getfh

nfs4_xdr_dec_open
 decode_sequence
 decode_putfh
 decode_open
 decode_getfh
  memcpy(fh->data, p, len) // 获取目标文件的 filehandle


server端
// 获取目标文件的 filehandle (svc_fh)，打开本地文件生成 struct file ，保存在 nfsd_file 中， nfsd_file 保存在 nfsd_file_hashtbl[hashval] 链表中
nfsd4_decode_open
 open->op_seqid
 open->op_share_access
 open->op_deleg_want
 open->op_share_deny
 open->op_clientid
 open->op_owner // struct xdr_netobj
                // 来源于client端 p->o_arg.id.create_time 和 p->o_arg.id.uniquifier
                // 打开操作的时间和client端id
 open->op_create
  open->op_createmode
 open->op_claim_type

nfsd4_open
<---------------- open1 ---------------->
/*
 * 两个重要的结构 nfs4_openowner 和 nfs4_ol_stateid
 * nfs4_openowner 是一个客户端打开文件的实例
 * nfs4_file 是一个全局的文件结构体
 */
 // 校验 seqid 设置 nfs4_openowner
 nfsd4_process_open1
  nfsd4_alloc_file // 分配 nfs4_file 保存在 open->op_file
  ownerstr_hashval // 根据 open->op_owner 计算hash
  find_openstateowner_str // 根据 hash 查找是否已有当前客户端本次打开的实例
   find_openstateowner_str_locked // 在 clp->cl_ownerstr_hashtbl[hashval] 链表中查找指定 nfs4_openowner
  alloc_init_open_stateowner // 分配新的 nfs4_openowner
   alloc_stateowner // 分配 nfs4_stateowner 即 nfs4_openowner(nfs4_stateowner为nfs4_openowner第一个内嵌成员)
   hash_openowner // 将新的 nfs4_openowner 插入 clp->cl_ownerstr_hashtbl[strhashval] 链表
  open->op_openowner // 将新的 nfs4_openowner 的指针保存到 nfs4_open 中
  nfs4_alloc_open_stateid // 分配 nfs4_ol_stateid 保存在 open->op_stp 中
   nfs4_alloc_stid // 从 stateid_slab 中分配 nfs4_stid 即 nfs4_ol_stateid(nfs4_stid为nfs4_ol_stateid第一个内嵌成员)
    idr_alloc_cyclic // 分配一个基数树id(基数树为 cl->cl_stateids)，将 nfs4_stid 插入树中
 do_open_lookup // 第一次打开文件 NFS4_OPEN_CLAIM_NULL
  do_nfsd_create // 没有则创建
  nfsd_lookup // 有则查找
              // 获取到目标文件的 svc_fh
<---------------- open2 ---------------->
 // 在server端做实际的打开文件操作
 nfsd4_process_open2
  find_or_add_file // 在 file_hashtbl[hashval] 链表中查找目录对应的 nfs4_file
                   // 没找到则将 open->op_file 作为目录的 nfs4_file
  init_open_stateid // 初始化 open->op_stp nfs4_ol_stateid
   nfsd4_find_existing_open // 查看是否已有打开实例
  nfs4_inc_and_copy_stateid // 将 nfs4_stid.sc_stateid 赋值给 open->op_stateid 返回客户端
  nfs4_get_vfs_file
   nfsd_file_acquire
    nfsd_file_find_locked // 在 nfsd_file_hashtbl[hashval] 链表中查找目标文件对应的 nfsd_file
    nfsd_file_alloc // 分配 nfsd_file ，包含待打开文件的 inode
	hlist_add_head_rcu // 将新的 nfsd_file 插入 nfsd_file_hashtbl[hashval] 链表中
    nfsd_open_verified
	 __nfsd_open
	  dentry_open // 打开后端文件系统的文件，生成 file 保存在 nfsd_file->nf_file 中
  nfs4_open_delegation // server hand out a delegation to client
   nfs4_set_delegation
    nfs4_delegation_exists // 在 nfs4_file 的 fi_delegations 链表中查找当前与client匹配的 nfs4_delegation
	alloc_init_deleg // 分配初始化 nfs4_delegation
	 nfs4_alloc_stid // 从 deleg_slab 中分配 nfs4_stid 即 nfs4_delegation(nfs4_stid为nfs4_delegation第一个内嵌成员)
	nfs4_alloc_init_lease // 分配初始化 file_lock ，文件锁 ops nfsd_lease_mng_ops
	vfs_setlease // 参数：文件+租约类型(读/写)+文件锁
	 generic_setlease
	  generic_add_lease
	   locks_get_lock_context // 分配 file_lock_context 保存在 inode->i_flctx
	   time_out_leases // 获取超时的lease，加到 dispose 链表中
	   check_conflicting_open // 校验加锁是否有冲突
	   list_for_each_entry // 遍历 file_lock_context.flc_lease 链表
	   1) 遍历到其他lease且当前lease为写lock(F_WRLCK) 或 有lease处于 FL_UNLOCK_PENDING 状态
	    返回 -EAGAIN
	   2) 遍历到当前lease
	    nfsd_change_deleg_cb
	   3) 未遍历到lease
	    locks_insert_lock_ctx // 将当前lease加入 file_lock_context.flc_lease 链表
	hash_delegation_locked // 将 nfs4_delegation 加入 nfs4_file.fi_delegations 和 nfs4_client.cl_delegations 链表
 fh_dup2(&cstate->current_fh, resfh) // current_fh 设置成目标文件







nfsd4_encode_open
 nfsd4_encode_stateid // open->op_stateid

<---------------- 读文件 ---------------->
// NFSPROC4_CLNT_READ(client) = OP_SEQUENCE+OP_PUTFH+OP_READ

client端
nfs_initiate_pgio
 nfs_initiate_read
  nfs4_proc_read_setup
   nfs42_read_plus_support // NFSPROC4_CLNT_READ
   ...
   nfs4_xdr_enc_read
    encode_sequence
	encode_putfh
	encode_read
	 args->offset // 偏移
	 args->count // 长度
   nfs4_xdr_dec_read


server端
nfsd4_decode_read
 read->rd_offset
 read->rd_length

nfsd4_read
 nfs4_preprocess_stateid_op
  nfs4_check_file
   nfs4_find_file // 查找 nfsd_file 赋值给 nfsd4_read->rd_nf
 read->rd_rqstp = rqstp
 read->rd_fhp = &cstate->current_fh


nfsd4_encode_read
 file = read->rd_nf->nf_file; // 获取 struct file
 nfsd4_encode_readv // 从 file 中读数据



<---------------- 关闭文件 ---------------->
// NFSPROC4_CLNT_CLOSE(client) = OP_SEQUENCE+OP_PUTFH+OP_CLOSE

client端
nfs4_close_sync
 __nfs4_close
  nfs4_do_close
  ...
   nfs4_xdr_enc_close
    encode_sequence
	encode_putfh
	encode_close
	 arg->seqid
	 arg->stateid
   nfs4_xdr_dec_close


server端
nfsd4_decode_close
 close->cl_seqid
 close->cl_stateid

nfsd4_close
 nfs4_preprocess_seqid_op // 查找 nfs4_ol_stateid
 nfsd4_close_open_stateid // 释放 nfs4_ol_stateid
 nfs4_put_stid
  fp = s->sc_file // 获取 nfs4_file 即打开的底层文件的信息
  nfs4_free_ol_stateid // s->sc_free
   nfs4_put_stateowner
    nfs4_free_stateowner // 释放 nfs4_stateowner
  put_nfs4_file
   // 如何释放打开操作创建的 file

nfsd4_encode_close

@zz[
    filp_close+1
    nfsd_file_free+226
    nfsd_file_put_noref+337
    nfsd_file_dispose_list+161
    nfsd_file_delayed_close+338
    process_one_work+1035
    worker_thread+809
    kthread+516
    ret_from_fork+31
, kworker/2:1]: 1                 


nfsd_add_fcache_disposal
 list_add_tail_rcu(&l->list, &laundrettes)

nfsd_file_dispose_list_delayed
 nfsd_file_list_add_disposal

@zz[
    nfsd_file_dispose_list_delayed+1
    nfsd_file_lru_walk_list+376
    nfsd_file_gc_worker+20
    process_one_work+1035
    worker_thread+809
    kthread+516
    ret_from_fork+31
, kworker/2:1]: 2

INIT_DELAYED_WORK(&nfsd_filecache_laundrette, nfsd_file_gc_worker)

nfsd_file_put
 nfsd_file_schedule_laundrette

// umount 触发 ？
@xx[
    nfsd_file_put+1
    put_deleg_file+158
    destroy_unhashed_deleg+233
    nfsd4_delegreturn+596
    nfsd4_proc_compound+1777
    nfsd_dispatch+555
    svc_process+3173
    nfsd+506
    kthread+516
    ret_from_fork+31
, nfsd]: 1




/********************* 写文件 *********************/
echo 445566 > /mnt/sdb/dir/dir1/dir2/testfile

// access /mnt/sdb
// lookup dir
// lookup dir1
// lookup dir2
// access dir2
// open testfile
// write testfile
// close testfile


<---------------- 写文件 ---------------->
// NFSPROC4_CLNT_WRITE(client) = OP_SEQUENCE+OP_PUTFH+OP_WRITE
client端
nfs_writepages
 nfs_pageio_complete
  nfs_pageio_complete_mirror
   nfs_pageio_doio
    nfs_generic_pg_pgios
	 nfs_initiate_pgio
	  nfs_initiate_write
	   nfs_proc_write_setup // NFSPROC_WRITE
	   ...
	   nfs4_xdr_enc_write
	    encode_sequence
		encode_putfh
		encode_write // OP_WRITE
		 args->stateid
		 args->offset
		 args->stable
		 args->count
		 args->pages
		 args->pgbase
	   nfs4_xdr_dec_write


server端
nfsd4_decode_write
 write->wr_stateid <-- args->stateid // 打开文件时服务端返回的打开状态，用于标识服务器为客户端维护的与该文件相关的状态信息
 write->wr_offset <-- args->offset
 write->wr_stable_how <-- args->stable
 write->wr_buflen <-- args->count
 write->wr_pagelist <-- args->pages
 write->wr_head->iov_base <-- args->pgbase

nfsd4_write
 nfs4_preprocess_stateid_op
  nfsd4_lookup_stateid
   lookup_clientid // 根据 stateid->si_opaque.so_clid 查找 client
   find_stateid_by_type // 根据 t->si_opaque.so_id 从 cl->cl_stateids 查找 nfs4_stid
                        // 即 nfs4_ol_stateid (open时创建)
  nfsd4_stid_check_stateid_generation // 校验 stateid
  nfs4_check_fh // 校验 filehandle
  nfs4_check_file // 校验文件
 svc_fill_write_vector // 通过 sunrpc 接口将网络传递的数据保存在本地
 nfsd_vfs_write
  vfs_iter_write // 调用 vfs 接口向底层文件系统的文件写入数据

nfsd4_encode_write




/********************* 卸载 *********************/
GETATTR + DESTROY_SESSION + DESTROY_CLIENTID

<---------------- 销毁session ---------------->
NFSPROC4_CLNT_DESTROY_SESSION = OP_DESTROY_SESSION
client端
nfs4_destroy_session
 nfs4_proc_destroy_session
  ...
  nfs4_xdr_dec_destroy_session
  encode_destroy_session
   encode_op_hdr // OP_DESTROY_SESSION
   encode_opaque_fixed // session->sess_id.data
  nfs4_xdr_enc_destroy_session

server端
nfsd4_decode_destroy_session
 destroy_session->sessionid.data

nfsd4_destroy_session
 find_in_sessionid_hashtbl // 根据 sessionid 查找 nfsd4_session
  __find_in_sessionid_hashtbl // 在 nn->sessionid_hashtbl[idx] 中查找 nfsd4_session
 mark_session_dead_locked // ses->se_flags |= NFS4_SESSION_DEAD
 unhash_session // 从链表中删除
  list_del(&ses->se_hash)
  list_del(&ses->se_perclnt)
 nfsd4_put_session_locked
  free_session
   nfsd4_del_conns // 删除 nfsd4_conn
   nfsd4_put_drc_mem // ses->se_fchannel nfsd4_channel_attrs
   __free_session
    free_session_slots // 释放 nfsd4_slot
	kfree // 释放 nfsd4_session


<---------------- 销毁clientid ---------------->
NFSPROC4_CLNT_DESTROY_CLIENTID = OP_DESTROY_CLIENTID

client端
nfs4_proc_destroy_clientid
 _nfs4_proc_destroy_clientid
 ...
  nfs4_xdr_enc_destroy_clientid
   encode_destroy_clientid // clp->cl_clientid
  nfs4_xdr_dec_destroy_clientid


server端
nfsd4_decode_destroy_clientid
 dc->clientid

nfsd4_destroy_clientid
 find_unconfirmed_client // 查找 unconfirmed 的 nfs4_client
 find_confirmed_client // 查找 confirmed 的 nfs4_client
 mark_client_expired_locked // 如果是 confirmed 的 nfs4_client
  unhash_client_locked // 从链表中删除 nfs4_client
 unhash_client_locked
 expire_client
  unhash_client
  nfsd4_client_record_remove
  __destroy_client



<---------------- client端返还 delegation ---------------->
NFSPROC4_CLNT_DELEGRETURN

client端
nfs_do_return_delegation
 nfs4_proc_delegreturn
  _nfs4_proc_delegreturn
  ...
  nfs4_xdr_enc_delegreturn
   encode_putfh
   encode_delegreturn
    encode_op_hdr // OP_DELEGRETURN
	encode_nfs4_stateid // stateid
  nfs4_xdr_dec_delegreturn


server端
nfsd4_decode_delegreturn
 nfsd4_decode_stateid
  sid->si_generation
  sid->si_opaque

nfsd4_delegreturn
 nfsd4_lookup_stateid // 查找 nfs4_stid
 delegstateid // 转换成 nfs4_delegation
 nfsd4_stid_check_stateid_generation // 校验 nfs4_delegation
 destroy_delegation // 销毁 nfs4_delegation
  unhash_delegation_locked // 从链表中删除 nfs4_delegation
  destroy_unhashed_deleg // 文件解锁，释放资源
   nfs4_unlock_deleg_lease
    vfs_setlease // F_UNLCK
	 generic_setlease
	  generic_delete_lease
	   nfsd_change_deleg_cb // fl->fl_lmops->lm_change
	    lease_modify
		 locks_wake_up_blocks // 唤醒被阻塞的锁



<---------------- nfs4.0 客户端续约 ---------------->
需要客户端发送专门的RENEW操作实现续约

NFSPROC4_CLNT_RENEW
OP_RENEW

client端
nfs4_renew_state
 nfs4_proc_async_renew // ops->sched_state_renewal
 ...
  nfs4_xdr_enc_renew
   encode_compound_hdr
   encode_renew
    encode_op_hdr // OP_RENEW
	encode_uint64 // clp->cl_clientid
  nfs4_xdr_dec_renew


server端
nfsd4_decode_renew
 clientid <-- clp->cl_clientid

nfsd4_renew
 lookup_clientid
  find_confirmed_client
   find_client_in_id_table
    renew_client_locked
	 list_move_tail(&clp->cl_lru, &nn->client_lru) // 将client移到链表末尾
	 clp->cl_time = ktime_get_boottime_seconds() // 更新 cl_time



<---------------- nfs4.1 客户端续约 ---------------->
每一个复合请求都能实现续约

client端
周期性发送 OP_SEQUENCE
@x[
    rpc_run_task+1
    _nfs41_proc_sequence+693
    nfs41_proc_async_sequence+29
    nfs4_renew_state+316
    process_one_work+1035
    worker_thread+809
    kthread+516
    ret_from_fork+31
, kworker/7:1]: 1

server端
nfsd
 svc_process
  svc_process_common
   nfsd_dispatch // process.dispatch
    nfs4svc_decode_compoundargs // proc->pc_decode
	nfsd4_proc_compound // proc->pc_func
	 nfsd4_encode_operation
	  nfsd4_enc_ops[op->opnum] // 每个单独操作对应的 encode
    nfs4svc_encode_compoundres // proc->pc_encode
	 nfsd4_sequence_done
	  nfsd4_put_session
	   nfsd4_put_session_locked
	    put_client_renew_locked
		 renew_client_locked




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

```

## 挂载流程
```
// 用户态命令
mount -t nfs4 -o rw 192.168.240.251:/sdb /mnt/sdb
// 内核参数
192.168.240.251:/sdb  nfs4 vers=4.2,addr=192.168.240.251,clientaddr=192.168.240.250


dev_name 192.168.240.251:/sdb
dir_name // 挂载目录，用户态字符串，打印不出来
type_page nfs4
data_page vers=4.2,addr=192.168.240.251,clientaddr=192.168.240.250


inode 所在的 nfs_inode 包含 nfs_fh ，指向 server 的文件(目录)

do_mount
 path_mount
  do_new_mount
   get_fs_type // 根据 "type_page nfs4" 获取对应的 file_system_type &nfs4_fs_type
   fs_context_for_mount // 初始化 fs_context nfs_fs_context
    // fc->ops = &nfs_fs_context_ops
	// fc->fs_private --> nfs_fs_context ctx --> nfs_fh mntfh
	  nfs_init_fs_context
	   nfs_alloc_fhandle // 分配 ctx->mntfh
<---------------- ctx 保存在 fc 中 ---------------->
   vfs_parse_fs_string // 根据 "dev_name 192.168.240.251:/sdb" 构造 source 参数，对应 Opt_source
    nfs_fs_context_parse_param
	 // fc->source = 192.168.240.251:/sdb
   parse_monolithic_mount_data // 根据 "data_page" 进行参数解析
    nfs_fs_context_parse_monolithic
	 nfs4_parse_monolithic
	  // ctx->version = 4
	  generic_parse_monolithic
	   nfs_fs_context_parse_param
	    // vers=4.2 --> Opt_vers --> Opt_vers_4_2
		   ctx->version = 4; ctx->minorversion = 2;
		// addr=192.168.240.251 --> Opt_addr
		   ctx->nfs_server.address <-- 192.168.240.251
		// clientaddr=192.168.240.250 --> Opt_clientaddr
		   ctx->client_address <-- 192.168.240.250
   vfs_get_tree
    nfs_get_tree // ctx->internal 默认 false
	 nfs_fs_context_validate
	  nfs_parse_source
<---------------- ctx->nfs_server 保存 server 的地址和导出路径 ---------------->
	   // ctx->nfs_server.hostname <-- dev_name 192.168.240.251
	   // ctx->nfs_server.export_path <-- dev_name /sdb
	  get_nfs_version // 初始化 ctx->nfs_mod  struct nfs_subversion nfs_v4
	 nfs4_try_get_tree
	  do_nfs4_mount
<---------------- 创建初始化 nfs_server ---------------->
	   nfs4_create_server // 创建初始化 nfs_server
	    nfs_alloc_server
	    nfs4_init_server
		 nfs4_set_client
<---------------- 创建初始化 nfs_client ---------------->
		  nfs_get_client
		   nfs_match_client // 从 nn->nfs_client_list 中匹配已有的 nfs_client
		    1) 返回已找到的 nfs_client
		    nfs_found_client
			2) 分配初始化 nfs_client 并插入 nn->nfs_client_list 链表
			nfs4_alloc_client
			 nfs_create_rpc_client // 创建 rpc_clnt
			nfs4_init_client
		  // server->nfs_client = clp
		      nfs4_discover_server_trunking
			   nfs41_discover_server_trunking
/********************* nfs4_proc_exchange_id *********************/
			    nfs4_proc_exchange_id
			    nfs4_schedule_state_manager
			     nfs4_run_state_manager
			     ...
				  nfs4_reset_session
/********************* nfs4_proc_create_session *********************/
			       nfs4_proc_create_session
				  nfs4_state_end_reclaim_reboot
				   nfs4_reclaim_complete
/********************* nfs41_proc_reclaim_complete *********************/
				    nfs41_proc_reclaim_complete
		nfs4_server_common_setup // 入参 struct nfs_fh *mntfh 的 size 为0，说明 mntfh 在该函数中初始化
<---------------- 探测 root fh ---------------->
		 nfs4_get_rootfh // 从 server 获取 root filehandle
		  nfs4_proc_get_rootfh
		   nfs41_find_root_sec
<---------------- 远程调用 NFSPROC4_CLNT_SECINFO_NO_NAME ---------------->
/********************* nfs41_proc_secinfo_no_name *********************/
		    nfs41_proc_secinfo_no_name // 对应 server 操作 OP_SECINFO_NO_NAME
			 ...
			 nfs4_xdr_enc_secinfo_no_name
			 nfs4_xdr_dec_secinfo_no_name
		    nfs4_lookup_root_sec
/********************* nfs4_lookup_root *********************/
			 nfs4_lookup_root
<---------------- 远程调用 NFSPROC4_CLNT_LOOKUP_ROOT ---------------->
			  _nfs4_lookup_root
			   ...
			   nfs4_xdr_enc_lookup_root
			   nfs4_xdr_dec_lookup_root
/********************* nfs4_server_capabilities *********************/
		   nfs4_server_capabilities
/********************* nfs4_do_fsinfo *********************/
		   nfs4_do_fsinfo
>>
	mntfh 只包含 server 信息，不包含导出目录("/sdb")的信息？
	fsid=0 的导出文件系统？
	根据 nfs4_server_common_setup 中打印的 Server FSID: 0:0 ， mntfh 应该只包含 fsid=0 的文件系统
// /etc/exports fsid=
// 设置被导出文件系统的id用于NFS模块识别。对于NFSv4，有一个独特的文件系统(fsid=root或fsid=0)，它是所有导出的文件系统的根。
<<
		 nfs_display_fhandle // 打印 mntfh
[2024-04-01 15:44:59]  [  123.716978] Server FSID: 0:0
[2024-04-01 15:44:59]  [  123.717532] Pseudo-fs root FH at 00000000f38d5545 is 8 bytes, crc: 0x62d40c52:
		 nfs_probe_fsinfo
/********************* nfs4_server_capabilities *********************/
		  nfs4_server_capabilities // clp->rpc_ops->set_capabilities
		  nfs4_proc_fsinfo
/********************* nfs4_do_fsinfo *********************/
		   nfs4_do_fsinfo
/********************* nfs4_proc_pathconf *********************/
		  nfs4_proc_pathconf
	   vfs_dup_fs_context // 分配初始化新的 fs_context *root_fc
	    nfs_fs_context_dup // 初始化 nfs_fs_context
		 nfs_alloc_fhandle // 分配 ctx->mntfh
	   // root_ctx->internal = true;
	   vfs_parse_fs_param // 初始化 root_fc 的 source
	   fc_mount
	    vfs_get_tree
		 nfs_get_tree
		  nfs_get_tree_common
		   sget_fc // 创建初始化 super_block
		    nfs_compare_super // fc->fs_type->fs_supers 查找比较是否已有 super_block
			alloc_super // 分配超级块 nfs4_fs_type
			nfs_set_super // 设置 s_d_op，默认 dentry_operations
			 s->s_d_op = server->nfs_client->rpc_ops->dentry_ops // nfs_v4_clientops --> nfs4_dentry_operations
			 s->s_fs_info = server // nfs_server 保存在超级块的 s_fs_info 中
		   nfs_fill_super // 根据 nfs_fs_context 填充 super_block
		    // nfs_v4 --> nfs4_xattr_handlers
		    sb->s_xattr = server->nfs_client->cl_nfs_mod->xattr
			// nfs_v4 --> nfs4_sops
			sb->s_op = server->nfs_client->cl_nfs_mod->sops
		   nfs_get_root // 创建初始化 根 inode/dentry
		    nfs_alloc_fattr // 分配初始化 nfs_fattr
			nfs4_label_alloc // 分配初始化 安全标签 nfs4_label
			nfs4_proc_get_root // server->nfs_client->rpc_ops->getroot
/********************* nfs4_server_capabilities *********************/
			 nfs4_server_capabilities // 通过远程调用获取 server 能力集 NFSPROC4_CLNT_SERVER_CAPS
									  // 根据远程调用返回的结果设置 nfs_server 的功能
			  _nfs4_server_capabilities 
			   // 构造 rpc_message
			    // .rpc_proc
				// nfs4_xdr_enc_server_caps
				// nfs4_xdr_dec_server_caps
				// .rpc_argp = &args,
				// .rpc_resp = &res,
<---------------- 远程调用 NFSPROC4_CLNT_SERVER_CAPS ---------------->
			   nfs4_do_call_sync
			    ...
				nfs4_xdr_enc_server_caps // GETATTR_BITMAP 请求编码
				nfs4_xdr_dec_server_caps // GETATTR_BITMAP 返回结果解码
<---------------- 远程调用 NFSPROC4_CLNT_GETATTR ---------------->
/********************* nfs4_proc_getattr *********************/
			 nfs4_proc_getattr // 通过远程调用获取 server 属性 NFSPROC4_CLNT_GETATTR
			  nfs4_do_call_sync
			   ...
			   nfs4_xdr_enc_getattr // GETATTR 请求编码
			   nfs4_xdr_dec_getattr // GETATTR 返回结果解码
			nfs_fhget // 根 inode -- 包含 nfs_fattr nfs_fh 若需要，也包含安全标签 nfs4_label
<---------------- 创建初始化 inode(包含server信息，不包含导出目录信息) ---------------->
			 iget5_locked
			  alloc_inode // 分配 inode
			  inode_insert5 // 添加到全局 inode_hashtable 链表和超级块链表 inode->i_sb->s_inodes
			   nfs_init_locked
			    set_nfs_fileid // nfs_fattr 保存到 nfs_inode->fileid 
				nfs_copy_fh // nfs_fh 保存到 nfs_inode->fh
<---------------- 将 nfs_fh 保存在 inode 所在的 nfs_inode 中 ---------------->
			 // inode->i_ino = hash
			d_obtain_root // root 结点对应的 dentry
			// root->d_fsdata = name = fc->source
			// fc->root = root
<---------------- 创建初始化 mount(包含server信息，不包含导出目录信息) ---------------->
		vfs_create_mount // 创建 root_mnt (struct mount)
		 // mnt->mnt.mnt_root       = dget(fc->root)
		 // mnt->mnt_mountpoint     = mnt->mnt.mnt_root;
// fc_mount
	   mount_subtree // 传入 root_mnt 与 export_path ("/sdb")
	    alloc_mnt_ns // 分配 mnt_namespace 并与 mount 绑定
<---------------- 在包含server信息的dentry下查找导出目录export_path ---------------->
		vfs_path_lookup // 在包含server信息
		 filename_lookup
		  set_nameidata // 创建初始化 nameidata
		  path_lookupat
		   link_path_walk
		    may_lookup
			 inode_permission
			  do_inode_permission
			   nfs_permission
			    nfs_do_access
/********************* nfs4_proc_access *********************/
				 nfs4_proc_access
		   lookup_last
		    walk_component
			 lookup_fast // 快速路径，在当前目录entry下未找到对应的dentry
			 lookup_slow // 慢速路径，在当前目录entry下创建新的dentry
			  __lookup_slow
			   nfs4_proc_lookup
			    nfs4_proc_lookup_common
/********************* _nfs4_proc_lookup *********************/
				 _nfs4_proc_lookup
			 step_into
			  handle_mounts
			   traverse_mounts
			    __traverse_mounts
				 follow_automount
				  nfs_d_automount
				   fs_context_for_submount // 创建初始化新的 fs_context，会有新的 nfs_fh
				    // fc->fs_private --> nfs_fs_context ctx --> nfs_fh mntfh
				   nfs4_submount
					nfs4_proc_lookup_mountpoint
					 nfs4_proc_lookup_common
<---------------- 远程调用 NFSPROC4_CLNT_LOOKUP ---------------->
/********************* _nfs4_proc_lookup *********************/
					  _nfs4_proc_lookup
					   ...
					   nfs4_xdr_enc_lookup
					   nfs4_xdr_dec_lookup
					   // "/sdb" 对应的 nfs_fh 保存到 ctx->mntfh 中
					nfs_do_submount
					 nfs_clone_server
					  nfs_probe_fsinfo
/********************* nfs4_server_capabilities *********************/
					   nfs4_server_capabilities
					   nfs4_proc_fsinfo
/********************* nfs4_do_fsinfo *********************/
					    nfs4_do_fsinfo
/********************* nfs4_proc_pathconf *********************/
					   nfs4_proc_pathconf
					 vfs_get_tree
					  nfs_get_tree
					   nfs_get_tree_common
					    nfs_get_root
						 nfs4_proc_get_root
/********************* nfs4_server_capabilities *********************/
						  nfs4_server_capabilities
				   vfs_create_mount / 创建 "/sdb" 对应的 root_mnt (struct mount)

1、获取 fsid=0 的文件系统对应的 root nfs_fh
2、获取当前导出目录的文件系统对应的 nfs_fh

struct nfs_inode {
    __u64              fileid;
    struct nfs_fh      fh;
...
    struct inode       vfs_inode;
};

struct nfs_fh {
    short unsigned int size;
    // 保存服务端文件句柄，用于提供给服务端识别，指定当前操作的目标文件
    unsigned char      data[128];
};


mount 与 submount 之间延时，延时
```

## 并发控制
```
// client1写打开文件后，client2写打开同一个文件
// 有冲突
1) 服务端查找是否有冲突
2) 有冲突时将 delegation 加入删除链表(del_recall_lru)并向之前的客户端发送 recall
>> recall 没有及时返回怎么处理，及时返回后怎么处理？
recall会立刻返回，之后客户端如果发出 OP_DELEGRETURN 请求主动放弃 delegation，则服务端立刻销毁 delegation ，否则等超时销毁
3) 删除链表(del_recall_lru)中的 delegation 在宽限期结束后会删除

nfsd4_open
<---------------- open1 ---------------->
 nfsd4_process_open1
  nfsd4_alloc_file // 分配 nfs4_file 保存在 open->op_file
  find_openstateowner_str // 当前客户端没有打开过该文件
  alloc_init_open_stateowner // 分配新的 nfs4_openowner
  nfs4_alloc_open_stateid // 分配 nfs4_ol_stateid 保存在 open->op_stp 中
<---------------- open2 ---------------->
 nfsd4_process_open2
  find_or_add_file // 根据 current_fh->fh_handle 查找目标文件 nfs4_file
                   // 每个客户端有自己的 struct svc_fh *current_fh，有自己的 current_fh->fh_handle
				   // 但每个文件的 knfsd_fh 是全局唯一的，其中根目录的 knfsd_fh 是 OP_PUTROOTFH 操作设置的
  nfs4_check_deleg
   find_deleg_stateid // 根据 open->op_delegate_stateid 查找 nfs4_delegation
                      // 在创建 nfs4_delegation 时会将id保存在 op_delegate_stateid 中返回给客户端
					  // 若当前客户端再次尝试打开该文件时，则可以根据该id找到服务端之前为这个客户端分配的 nfs4_delegation
  nfsd4_find_and_lock_existing_open
   nfsd4_find_existing_open // 在 nfs4_file.fi_stateids 链表中查找当前owner的NFS4_OPEN_STID实例
  init_open_stateid // 创建 nfs4_ol_stateid
  nfs4_get_vfs_file
   if (!fp->fi_fds[oflag]) // 不满足
    nfsd_open_break_lease
	 break_lease
	  __break_lease // FL_LEASE
	   lease_alloc // 分配新的文件锁
	    lease_init // fl->fl_flags = FL_LEASE
		 assign_type // fl->fl_type 锁类型，写锁 F_WRLCK
	   time_out_leases // 获取超时的lease，加到 dispose 链表中
	   any_leases_conflict
	    leases_conflict // 当前新的文件锁与该文件上已有的文件锁是否有冲突
		 nfsd_breaker_owns_lease // lease->fl_lmops->lm_breaker_owns_lease
		  i_am_nfsd // 非 nfsd 线程直接退出
<------------ 如果与所有的锁都没有冲突则直接返回 ------------>
       break_time = jiffies + lease_break_time * HZ; // 计算超时时间
	   list_for_each_entry_safe(fl, tmp, &ctx->flc_lease, fl_list) // 遍历查找冲突锁
	    fl->fl_flags |= FL_UNLOCK_PENDING; // 冲突锁标记为 FL_UNLOCK_PENDING
		fl->fl_break_time = break_time; // 为冲突锁设置超时时间
		nfsd_break_deleg_cb // fl->fl_lmops->lm_break
		 fl->fl_break_time = 0; // 清超时时间
		 fp->fi_had_conflict = true; // 设置冲突标记
		 nfsd_break_one_deleg
		  refcount_inc(&dp->dl_stid.sc_count) // 增加 nfs4_delegation 引用计数
		  nfsd4_run_cb // nfsd4_cb_recall_ops
		   nfsd4_queue_cb
		    queue_work(callback_wq, &cb->cb_work) // 异步执行 recall 回调
<------------ 异步执行 recall 回调 ------------>
	   locks_insert_block // 将当前新锁加入阻塞新锁的旧锁 fl_blocked_requests 链表中
	    __locks_insert_block
		 list_add_tail
		 __locks_wake_up_blocks // 唤醒阻塞在waiter上的锁，这里无意义，没有锁阻塞在新锁上
	   wait_event_interruptible_timeout // 等待阻塞新锁的旧锁唤醒
	   1) 客户端返回 OP_DELEGRETURN 请求后唤醒
	   2) nfs4_laundromat 超时删除 delegation 后唤醒
	   any_leases_conflict // 再次校验是否有冲突
	   1) 有冲突
	    goto restart
	   2) 无冲突
	    return


// 异步执行 recall 回调
nfsd4_run_cb_work
 nfsd4_cb_recall_prepare // cb->cb_ops->prepare  dp->dl_recall nfsd4_cb_recall_ops
  block_delegations // 将当前 filehandle(knfsd_fh) 设置为 delegation_blocked 状态
  dp->dl_time = ktime_get_boottime_seconds(); // 设置 dl_time
  list_add_tail(&dp->dl_recall_lru, &nn->del_recall_lru) // 将 nfs4_delegation 插入删除队列 del_recall_lru
<------------ 异步进程处理待删除的 nfs4_delegation ------------>
 // nfsd4_setclientid_confirm --> nfsd4_probe_callback 设置了 NFSD4_CLIENT_CB_FLAG_MASK
 nfsd4_process_cb_update
  __nfsd4_find_backchannel // 查找反向通道
   list_for_each_entry // 遍历 nfs4_client.cl_sessions 链表查找session
    list_for_each_entry // 遍历 nfsd4_session.se_conns 链表查找session上的conn
	 // 返回查找到 nfsd4_conn
  setup_callback_client // 创建 rpc_clnt 用于反向发送请求，保存在 nfs4_client.cl_cb_client 中
 rpc_call_async
  rpc_run_task // callback_ops 为 nfsd4_cb_ops 或 nfsd4_cb_probe_ops
   // NFSPROC4_CLNT_CB_RECALL
   ...
   nfs4_xdr_enc_cb_recall
	encode_cb_compound4args
	encode_cb_sequence4args
	encode_cb_recall4args
	 encode_nfs_cb_opnum4 // OP_CB_RECALL
	 encode_stateid4 // dp->dl_stid.sc_stateid
	 encode_nfs_fh4 // dp->dl_stid.sc_file->fi_fhandle
   nfs4_xdr_dec_cb_recall



// 异步进程处理待删除的 nfs4_delegation (del_recall_lru)
nfs4_laundromat
 if (dp->dl_time > cutoff) // 判断租约是否到期
 1) 没到期
  退出，等待下次调度
 2) 到期
  unhash_delegation_locked // 从链表中删除 nfs4_delegation
  list_add(&dp->dl_recall_lru, &reaplist) // 插入 reaplist 链表
  while (!list_empty(&reaplist))
   // 释放 nfs4_delegation
   revoke_delegation
    destroy_unhashed_deleg
	 nfs4_unlock_deleg_lease
	  vfs_setlease // 解除文件锁



<------------ 客户端接收处理 cb_recall ------------>
// 挂载的时候初始化
nfs4_init_client
 nfs4_init_client_minor_version
  nfs4_init_callback
   nfs_callback_up
    nfs_callback_create_svc
	 svc_create_pooled // nfs4_callback_program
	  // serv->sv_ops <-- nfs4_cb_sv_ops[0]/nfs4_cb_sv_ops[1]
	  __svc_create
    nfs_callback_start_svc // 默认线程数 NFS4_MIN_NR_CALLBACK_THREADS
	 svc_set_num_threads_sync // serv->sv_ops->svo_setup
	  svc_start_kthreads
	   kthread_create_on_node // 创建进程，运行函数 nfs4_callback_svc
	   wake_up_process // 启动进程

// 接收处理服务端 cb_recall 请求
nfs4_callback_svc
 svc_recv
 svc_process
 ...
  nfs4_callback_compound // pc_func
   process_op
    decode_recall_args // op->decode_args [OP_CB_RECALL]
	 args->stateid <-- dp->dl_stid.sc_stateid
	 args->fh <-- dp->dl_stid.sc_file->fi_fhandle
	nfs4_callback_recall // op->process_op [OP_CB_RECALL]
	 nfs_delegation_find_inode // 根据 filehandle 查找 inode
	 nfs_async_inode_return_delegation
	  nfs_mark_return_delegation
	   // NFS_DELEGATION_RETURN NFS4CLNT_DELEGRETURN 标记当前 delegation 需要返回
	  nfs_delegation_run_state_manager
	   nfs4_schedule_state_manager
	    // 异步执行 nfs4_run_state_manager
	 nfs_iput_and_deactive
	encode_op_hdr // 将结果编码，通过 sunrpc 框架返回给服务端
（由于返回 delegation 的操作是异步执行，客户端给服务端返回 recall 结果时可能并没有返回 delegation）


nfs4_run_state_manager
 nfs4_state_manager
  nfs_client_return_marked_delegations
   nfs_client_for_each_server
    // test_and_clear_bit NFS4CLNT_DELEGRETURN
    nfs_server_return_marked_delegations
	 nfs_end_delegation_return
	  nfs_delegation_claim_opens
	   nfs4_open_delegation_recall
	    nfs4_open_recover_helper
		 _nfs4_recover_proc_open
		  nfs4_run_open_task // 发送 NFSPROC4_CLNT_OPEN 请求
		 nfs4_close_state // 发送 NFSPROC4_CLNT_CLOSE 请求
	   nfs_delegation_claim_locks
	    nfs4_lock_delegation_recall
		 _nfs4_do_setlk // 发送 NFSPROC4_CLNT_LOCK 请求
	  nfs_do_return_delegation
	   nfs4_proc_delegreturn
	    _nfs4_proc_delegreturn // 发送 NFSPROC4_CLNT_DELEGRETURN 请求

/* 如果服务端想要回收 delegation 时客户端正在写文件，客户端怎样处理？ */
客户端不断续约，不会释放delegation


服务端超时回收 delegation 后，没有 delegation 的客户端再发送写请求会怎样？
会失败，根据客户端发过来的stateid找不到有效的 delegation


// client1读打开文件后，client2写打开同一个文件
// 有冲突
  nfs4_get_vfs_file
   if (!fp->fi_fds[oflag]) // 满足
    nfsd_file_acquire // 此时服务端没有写打开的 nfsd_file ，会写打开文件生成写打开 nfsd_file
     nfsd_open_break_lease // 生成写打开 nfsd_file 后再回收 delegation


// client1写打开文件后，client2读打开同一个文件
// 有冲突
__break_lease
 leases_conflict // 冲突判断
  nfsd_breaker_owns_lease // lease->fl_lmops->lm_breaker_owns_lease
   // return dl->dl_stid.sc_client == clp  同一个客户端不冲突
  locks_conflict
   // sys_fl->fl_type == F_WRLCK
   // caller_fl->fl_type == F_WRLCK
   // 任何一方写打开则冲突

// client1读打开文件后，client2读打开同一个文件
// 无冲突


//client1进程1打开文件后，client1进程2打开同一个文件
// 无冲突


//client1进程1打开文件后，client1进程1再次打开同一个文件
// 无冲突

```
