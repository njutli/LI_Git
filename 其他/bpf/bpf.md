# 一、相关链接
https://ebpf.io/zh-hans/what-is-ebpf/

https://blog.csdn.net/legend050709/article/details/128387908

https://blog.csdn.net/qq_17045267/article/details/103764320

# 二、用例执行步骤
```
ulimit -l unlimited
gcc -O2 -Wall -pthread bpf_loader_v2.c -o bpf_loader_v2
clang -O2 -target bpf -c bpf_simple.c -o bpf_simple.o
./bpf_loader_v2 bpf_simple.o
```

# 三、为什么要两个文件
```
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

两个文件的定位不同：
一个是内核态执行，功能单一，必须用 clang -target bpf 编译，只能使用受限的 C 子集，只能用 BPF helper 函数
一个是用户态执行，功能完善，用普通 gcc/clang 编译，是完整的用户空间程序， 可以用 printf、文件操作、网络等，可以解析 ELF、处理参数

单文件方案：
✅ bpftrace/bcc 脚本（Python/DSL）
❌ 纯 C 不现实（除非用非常复杂的构建系统）

```

# 四、代码与流程分析
## 1 流程分析
### 1.1 准备流程
1. 加载ELF文件
```
// 1. 打开文件
FILE *fp = fopen(argv[1], "rb");

// 2. 读取 ELF，找到名为 "tracepoint/syscalls/sys_enter_execve" 的 section
// 这个 section 包含 BPF 字节码指令

// 3. 调用 bpf() 系统调用，把字节码加载到内核
union bpf_attr attr = {
    .prog_type = BPF_PROG_TYPE_TRACEPOINT,  // 类型：tracepoint
    .insns = prog_instructions,              // BPF 字节码
    .insn_cnt = instruction_count,
    .license = "GPL",
};
int prog_fd = bpf(BPF_PROG_LOAD, &attr, sizeof(attr));
```

2. 内核验证并 JIT 编译
```
用户空间                    内核空间
─────────────────────────────────────────────────
  BPF 字节码                                   
     ↓                                         
 bpf() 系统调用  ──────→  ┌─────────────────┐
                          │ BPF Verifier    │
                          │ (安全检查)       │
                          └────────┬────────┘
                                   ↓
                          ┌─────────────────┐
                          │ JIT Compiler    │
                          │ (编译成机器码)   │
                          └────────┬────────┘
                                   ↓
                          ┌─────────────────┐
                          │ 机器码存在内核   │
                          │ 等待被触发       │
                          └─────────────────┘
```

3. 附加到 tracepoint
```
// 加载器打开 tracepoint 事件
int tp_fd = open("/sys/kernel/debug/tracing/events/syscalls/sys_enter_execve/id");

// 调用 ioctl 把 BPF 程序附加到这个 tracepoint
ioctl(tp_fd, PERF_EVENT_IOC_SET_BPF, prog_fd);
ioctl(tp_fd, PERF_EVENT_IOC_ENABLE, 0);
```

### 1.2 抓取流程
```
用户空间                 内核空间
─────────────────────────────────────────────────────

$ ls                    
  ↓
  调用 execve()
  系统调用
       ↓
   ┌────────┐            ┌──────────────────────┐
   │        │  进入内核   │  sys_execve()        │
   │  用户  │ ─────────→ │  (内核函数)          │
   │  进程  │            └──────────┬───────────┘
   └────────┘                       ↓
                         ┌──────────────────────┐
                         │ Tracepoint 触发点    │
                         │ trace_sys_enter()    │
                         └──────────┬───────────┘
                                    ↓
                         ┌──────────────────────┐
                         │ 内核检查：有 BPF     │
                         │ 程序附加在这里吗？    │
                         └──────────┬───────────┘
                                    ↓ 有！
                         ┌──────────────────────┐
                         │ 运行你的 BPF 程序:   │
                         │ trace_execve()       │
                         │   - 获取进程名       │
                         │   - 调用 bpf_printk  │
                         └──────────┬───────────┘
                                    ↓
                         ┌──────────────────────┐
                         │ 写入 trace buffer    │
                         └──────────────────────┘
                                    ↑
加载器读取 ──────────────────────┘
/sys/kernel/debug/tracing/trace_pipe
```

## 2. 内核代码分析
### 2.1 加载bpf程序

### 2.2 附加tracepoint

## 3. 二进制分析
**系统调用参数**
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

**执行结果**
```
[root@fedora ebpf]# ./bpf_loader_v2 bpf_simple.o
=== BPF Loader with Live Trace ===

[1] Loading bpf_simple.o
Found program section: tracepoint/syscalls/sys_enter_execve
  Offset: 0x40
  Size: 152 bytes
  Instructions: 19
Found license: GPL

[2] Loading into kernel...
SUCCESS! Program loaded, fd=3

[3] Attaching to tracepoint...
Tracepoint ID: 827
Attached successfully!

Monitoring execve calls... (Press Ctrl+C to stop)
Trace output will appear below:
────────────────────────────────────────


=== BPF Trace Output ===
           <...>-3815    [000] d... 92343.604776: bpf_trace_printk: execve: bash

```
