wb_workfn

// .write_iter
nfs_file_write
 generic_perform_write
  nfs_write_begin // a_ops->write_begin
   __filemap_get_folio // 根据 pos 获取folio
  copy_folio_from_iter_atomic // 用户态数据拷贝到 folio 中
  nfs_write_end // a_ops->write_end
   nfs_update_folio
    nfs_writepage_setup
     nfs_setup_write_request
	  nfs_try_to_update_request
	   nfs_lock_and_join_requests
	    nfs_folio_find_head_request
		 folio_test_private // 第一次没有对应的 nfs_page ， folio->private 为空
	  nfs_page_create_from_folio
	   nfs_page_create
	    nfs_page_alloc // 分配初始化 nfs_page ，初始计数为1
	   nfs_page_assign_folio // nfs_page 绑定 folio
	    req->wb_folio = folio
		folio_get
	   nfs_page_group_init // 初始化 page group，即以当前 nfs_page 为起始的一组 nfs_page
	    req->wb_head = req // 头指向自己
		req->wb_this_page = req // 下一个指向自己
	  nfs_inode_add_request // folio 绑定 nfs_page
	   folio_set_private
	   folio->private = req
	 nfs_grow_file // 更新文件大小
	 nfs_mark_uptodate // 将 folio 置为 uptodate
	 nfs_mark_request_dirty // 将 folio 置为 dirty

// 后台回写触发
nfs_writepages
 nfs_pageio_init_write // 初始化 nfs_pageio_descriptor
  nfs_pageio_init
   desc->pg_completion_ops = nfs_async_write_completion_ops
 writeback_iter
  writeback_get_folio // 获取待处理的 folio
 nfs_do_writepage
  nfs_lock_and_join_requests
   nfs_folio_find_head_request
    folio_test_private // 当前 folio->private 不为空
  nfs_pageio_add_request
   nfs_pageio_setup_mirroring // 分配 nfs_pgio_mirror
   nfs_create_subreq // 创建一个重复的 nfs_page
   nfs_pageio_add_request_mirror
    __nfs_pageio_add_request
	 nfs_pgio_current_mirror // 获取 nfs_pgio_mirror
	 nfs_pageio_do_add_request
	  nfs_coalesce_size
	    nfs_generic_pg_test // pgio->pg_ops->pg_test 检测 nfs_page 是否兼容 nfs_pageio_descriptor
							// 返回大小为 nfs_page 待写入字节和当前mirror剩余可写入字节中较小的一个值
		如果当前mirror剩余可写入字节不小于当前nfs_page携带的待写入字节，则将nfs_page加入mirror，否则创建一个新的，大小为mirror剩余可写入字节的nfs_page加入mirror
		如果mirror写满，则调用nfs_pageio_doio发送待写入数据
	  nfs_list_move_request // 将 req 添加到 &mirror->pg_list 链表中
 nfs_pageio_complete
  nfs_pageio_complete_mirror
   nfs_pageio_doio
    nfs_generic_pg_pgios
	 nfs_generic_pgio // 将 nfs_pageio_descriptor 中的req移到 nfs_pgio_header 中
	 nfs_initiate_pgio
	  nfs_initiate_write
	   nfs_proc_write_setup // NFSPROC_WRITE
	   // 请求类型是 NFSPROC_WRITE ，rpc_call_ops 是 nfs_pgio_common_ops

// 请求完成后
nfs_pgio_release
 nfs_write_completion // hdr->completion_ops->completion
  nfs_list_remove_request // 将 nfs_page 从 nfs_pgio_header 中取出
  nfs_page_end_writeback
   nfs_unlock_request
  nfs_release_request // 释放 nfs_page


问题流程
nfs_write_completion
 nfs_list_remove_request
  list_del_init // req->wb_list 将 nfs_page 从 nfs_pgio_header 中移除
 nfs_mark_request_commit
  nfs_request_add_commit_list
   nfs_request_add_commit_list_locked
    nfs_list_add_request
	 list_add_tail // req->wb_list 将 nfs_page 添加到 commit 链表 &NFS_I(inode)->commit_info
 nfs_page_end_writeback
  nfs_unlock_request // nfs_page 解锁

nfs_write_end
 nfs_update_folio
  nfs_writepage_setup
   nfs_setup_write_request
    nfs_try_to_update_request // merges the write into the original request
	 nfs_lock_and_join_requests
   nfs_grow_file // extend the file
   nfs_mark_request_dirty // re-marks the page dirty

// NFSPROC4_CLNT_COMMIT
nfs_commit_release_pages
 nfs_inode_remove_request


在已有数据回写的情况下，commit 与 write_iter 并发，write_iter将新写入的数据合并到已有req上并置脏，commit完成后将req与folio解除绑定（此时第一次写入的数据已经回写，第二次写入的数据虽然已经合并到已有req上，但还没有回写），由于folio->private被置为NULL，后续再出分 writepages 时第二次写入的数据也不会被回写




关键数据结构体 nfs_pageio_descriptor
req 是怎么生成的，怎么和 nfs_pageio_descriptor 里的链表关联的


1377 static const struct nfs_pgio_completion_ops nfs_async_write_completion_ops = {
1378         .init_hdr = nfs_async_write_init,
1379         .error_cleanup = nfs_async_write_error,
1380         .completion = nfs_write_completion,
1381         .reschedule_io = nfs_async_write_reschedule_io,
1382 };


// NFSPROC4_CLNT_COMMIT
nfs_commit_inode
 __nfs_commit_inode
  nfs_generic_commit_list
   nfs_commit_list
    nfs_init_commit // nfs_commit_ops
   nfs_initiate_commit

nfs_commit_release
 nfs_write_completion // data->completion_ops->completion
  nfs_inode_remove_request
   folio->private = NULL
   nfs_release_request
   ...
    nfs_page_group_destroy
     nfs_free_request
	  nfs_page_free
	   kmem_cache_free // nfs_page_cachep

// .write_iter
nfs_file_write
 generic_perform_write
  nfs_write_begin
   __filemap_get_folio // 根据 pos 获取folio
  nfs_write_end // a_ops->write_end
   nfs_update_folio
    nfs_writepage_setup
     nfs_setup_write_request
	  nfs_try_to_update_request
	   nfs_lock_and_join_requests
      nfs_page_create_from_folio
       nfs_page_create
        nfs_page_alloc

// .writepages
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


客户端写：
1、数据写入
先调 nfs_file_write (write_iter回调)，数据写入buffer
2、数据同步
2.1 通过 nfs_commit_inode (fsync等操作触发)，发送 NFSPROC4_CLNT_COMMIT 请求，服务端通过 nfsd4_commit 处理commit请求，如果nfsd配置了sync，那么数据就直接落盘
2.2 通过 nfs_writepages (后台回写等操作触发)， 发送 NFSPROC4_CLNT_WRITE 请求


