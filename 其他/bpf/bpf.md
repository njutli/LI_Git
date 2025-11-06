```
ulimit -l unlimited
clang -O2 -target bpf -c bpf_simple.c -o bpf_simple.o
./bpf_loader_v2 bpf_simple.o

┌─────────────────────────────────────┐
│        用户空间 (User Space)         │
│                                     │
│  bpf_loader_v2.c ← 你的加载器        │
│    ├─ 打开 .o 文件                   │
│    ├─ 解析 ELF                      │
│    ├─ 调用 bpf() 系统调用            │
│    └─ 读取 /sys/kernel/debug/tracing│
│                                     │
└─────────────────────────────────────┘
              ↕ (系统调用)
┌─────────────────────────────────────┐
│        内核空间 (Kernel Space)       │
│                                     │
│  bpf_simple.o ← 你的 BPF 程序        │
│    └─ 在内核中运行，监控 execve      │
│                                     │
└─────────────────────────────────────┘

┌──────────────────────────────────────────────────┐
│ 为什么需要两个文件？                              │
├──────────────────────────────────────────────────┤
│ 1. BPF 程序在内核运行，加载器在用户空间运行        │
│ 2. 需要不同的编译选项和目标                       │
│ 3. BPF 字节码需要从 .o 文件加载                   │
│ 4. 加载器需要解析 ELF、处理系统调用等复杂逻辑      │
└──────────────────────────────────────────────────┘

单文件方案：
✅ bpftrace/bcc 脚本（Python/DSL）
❌ 纯 C 不现实（除非用非常复杂的构建系统）

```

```
bpf(BPF_PROG_LOAD, 
{
	prog_type=BPF_PROG_TYPE_TRACEPOINT,
	insn_cnt=19,
	insns=[
	{
		code=BPF_ALU64|BPF_X|BPF_MOV,
		dst_reg=BPF_REG_6,
		src_reg=BPF_REG_10,
		off=0,
		imm=0
	},
	{
		code=BPF_ALU64|BPF_K|BPF_ADD,
		dst_reg=BPF_REG_6,
		src_reg=BPF_REG_0,
		off=0,
		imm=0xfffffff0
	},
	{
		code=BPF_ALU64|BPF_X|BPF_MOV,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_6,
		off=0,
		imm=0
	},
	{
		code=BPF_ALU64|BPF_K|BPF_MOV,
		dst_reg=BPF_REG_2,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x10
	},
	{
		code=BPF_JMP|BPF_K|BPF_CALL,
		dst_reg=BPF_REG_0,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x10
	},
	{
		code=BPF_ALU64|BPF_K|BPF_MOV,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x7325
	},
	{
		code=BPF_STX|BPF_H|BPF_MEM,
		dst_reg=BPF_REG_10,
		src_reg=BPF_REG_1,
		off=-24,
		imm=0
	},
	{
		code=BPF_LD|BPF_DW|BPF_IMM,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x63657865
	},
	{
		code=BPF_LD|BPF_W|BPF_IMM,
		dst_reg=BPF_REG_0,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x203a6576
	},
	{
		code=BPF_STX|BPF_DW|BPF_MEM,
		dst_reg=BPF_REG_10,
		src_reg=BPF_REG_1,
		off=-32,
		imm=0
	},
	{
		code=BPF_ALU64|BPF_K|BPF_MOV,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_0,
		off=0,
		imm=0
	},
	{
		code=BPF_STX|BPF_B|BPF_MEM,
		dst_reg=BPF_REG_10,
		src_reg=BPF_REG_1,
		off=-22,
		imm=0
	},
	{
		code=BPF_ALU64|BPF_X|BPF_MOV,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_10,
		off=0,
		imm=0
	},
	{
		code=BPF_ALU64|BPF_K|BPF_ADD,
		dst_reg=BPF_REG_1,
		src_reg=BPF_REG_0,
		off=0,
		imm=0xffffffe0
	},
	{
		code=BPF_ALU64|BPF_K|BPF_MOV,
		dst_reg=BPF_REG_2,
		src_reg=BPF_REG_0,
		off=0,
		imm=0xb
	},
	{
		code=BPF_ALU64|BPF_X|BPF_MOV,
		dst_reg=BPF_REG_3,
		src_reg=BPF_REG_6,
		off=0,
		imm=0
	},
	{
		code=BPF_JMP|BPF_K|BPF_CALL,
		dst_reg=BPF_REG_0,
		src_reg=BPF_REG_0,
		off=0,
		imm=0x6
	},
	{
		code=BPF_ALU64|BPF_K|BPF_MOV,
		dst_reg=BPF_REG_0,
		src_reg=BPF_REG_0,
		off=0,
		imm=0
	},
	{
		code=BPF_JMP|BPF_K|BPF_EXIT,
		dst_reg=BPF_REG_0,
		src_reg=BPF_REG_0,
		off=0,
		imm=0
	}],
	license="GPL",
	log_level=1,
	log_size=65536,
	log_buf="",
	kern_version=KERNEL_VERSION(5, 0, 0),
	prog_flags=0,
	prog_name="",
	prog_ifindex=0,
	expected_attach_type=BPF_CGROUP_INET_INGRESS,
	prog_btf_fd=0,
	func_info_rec_size=0,
	func_info=NULL,
	func_info_cnt=0,
	line_info_rec_size=0,
	line_info=NULL,
	line_info_cnt=0,
	attach_btf_id=0,
	attach_prog_fd=0,
	fd_array=NULL
},
144) = 3
```
