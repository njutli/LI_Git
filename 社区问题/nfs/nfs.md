【预设条件和测试步骤】
服务端：
mkfs.ext4 -F /dev/sdb
mount /dev/sdb /mnt/sdb
echo "/mnt *(rw,no_root_squash,fsid=0)" > /etc/exports
echo "/mnt/sdb *(rw,no_root_squash,fsid=1)" >> /etc/exports
systemctl restart nfs-server
echo 123 > /mnt/sdb/testfile

客户端1：
mount -t nfs -o rw 192.168.6.249:/sdb /mnt/sdbb

客户端2：
mount -t nfs -o rw 192.168.6.249:/sdb /mnt/sdbb

# 服务端等待 lm 释放
grace_list 打印为偶数次

客户端1：
exec 100</mnt/sdbb/testfile

服务端：
rpcdebug -m nfsd -s svc
rpcdebug -m nfsd -s proc

客户端2：
exec 100>/mnt/sdbb/testfile

服务端：
出现 "<<<<<<<<<<<<<<<<<<<<<<<<<<< nfs4_laundromat sleep for dp ffff9ac1dc5adac8 del <<<<<<<<<<<<<<<<<" 打印时执行
rpcdebug -m nfsd -c svc

下一次再出现 "<<<<<<<<<<<<<<<<<<<<<<<<<<< nfs4_laundromat sleep for dp ffff9ac1dc5adac8 del <<<<<<<<<<<<<<<<<" 时执行
rpcdebug -m nfsd -c proc
【测试用例代码（代码附件/“不涉及”）】
不涉及
【复现记录（可截图，需包含"uname -a"等版本信息）】
服务端不断给客户端的sequence请求设置SEQ4_STATUS_RECALLABLE_STATE_REVOKED
[2025-09-04 10:22:59] [ 1318.740037][ T2776] nfs41_handle_sequence_flag_errors detect SEQ4_STATUS_RECALLABLE_STATE_REVOKED
[2025-09-04 10:22:59] [ 1318.746963][ T2776] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 10:23:19] [ 1339.215099][ T2755] nfsd4_sequence set SEQ4_STATUS_RECALLABLE_STATE_REVOKED for seq
[2025-09-04 10:23:20] [ 1339.220237][ T2776] nfs41_handle_sequence_flag_errors detect SEQ4_STATUS_RECALLABLE_STATE_REVOKED
[2025-09-04 10:23:20] [ 1339.227205][ T2776] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 10:23:40] [ 1359.694777][ T2755] nfsd4_sequence set SEQ4_STATUS_RECALLABLE_STATE_REVOKED for seq
[2025-09-04 10:23:40] [ 1359.700228][ T2776] nfs41_handle_sequence_flag_errors detect SEQ4_STATUS_RECALLABLE_STATE_REVOKED
[2025-09-04 10:23:40] [ 1359.706971][ T2776] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 10:24:00] [ 1380.175088][ T2755] nfsd4_sequence set SEQ4_STATUS_RECALLABLE_STATE_REVOKED for seq
见附件
【验证记录（可截图，需包含"uname -a"等版本信息）】
服务端不设置SEQ4_STATUS_RECALLABLE_STATE_REVOKED
[2025-09-04 12:22:59] [ 2441.480289][ T2808] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 12:23:20] [ 2461.960586][ T2808] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 12:23:40] [ 2482.441271][ T2798] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 12:24:01] [ 2502.920497][ T2808] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 12:24:21] [ 2523.400494][ T2798] nfs4_schedule_state_renewal: requeueing work. Lease period = 20
[2025-09-04 12:24:42] [ 2543.880543][ T2808] nfs4_schedule_state_renewal: requeueing work. Lease period = 20

