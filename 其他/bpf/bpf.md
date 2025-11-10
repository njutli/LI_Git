# 一、相关链接
https://ebpf.io/zh-hans/what-is-ebpf/

https://blog.csdn.net/legend050709/article/details/128387908

https://blog.csdn.net/qq_17045267/article/details/103764320

# 二、用例执行
### 执行步骤
```
ulimit -l unlimited
gcc -O2 -Wall -pthread bpf_loader_v2.c -o bpf_loader_v2
clang -O2 -target bpf -c bpf_simple.c -o bpf_simple.o
终端1：./bpf_loader_v2 bpf_simple.o
终端2：ls
```
[bpf_loader_v2.c](https://github.com/njutli/LI_Git/blob/master/%E5%85%B6%E4%BB%96/bpf/bpf_loader_v2.c)

[bpf_simple.c](https://github.com/njutli/LI_Git/blob/master/%E5%85%B6%E4%BB%96/bpf/bpf_simple.c)

### 执行结果
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

# 三、为什么要两个文件
```
┌─────────────────────────────────────┐
│        用户空间 (User Space)         │
│                                     │
│  bpf_loader_v2.c ← 加载器        │
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
## 1. 流程分析
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

> 验证：<br>
> 验证步骤用来确保 eBPF 程序可以安全运行。它可以验证程序是否满足几个条件，例如：
> 1. 加载 eBPF 程序的进程必须有所需的能力（特权）。除非启用非特权 eBPF，否则只有特权进程可以加载 eBPF 程序。
> 2. eBPF 程序不会崩溃或者对系统造成损害。
> 3. eBPF 程序一定会运行至结束（即程序不会处于循环状态中，否则会阻塞进一步的处理）。

> JIT编译：<br>
> JIT (Just-in-Time) 编译步骤将程序的通用字节码转换为机器特定的指令集，用以优化程序的执行速度。这使得 eBPF 程序可以像本地编译的内核代码或作为内核模块加载的代码一样高效地运行。

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

## 2. 代码分析
### 2.1 解析ELF文件获取指令

1. 解析ELF文件
2. 获取指令地址

[bpf_simple二进制解析.md](https://github.com/njutli/LI_Git/blob/master/%E5%85%B6%E4%BB%96/bpf/bpf_simple%E4%BA%8C%E8%BF%9B%E5%88%B6%E8%A7%A3%E6%9E%90.md)

### 2.2 加载bpf程序指令到内核
#### 2.2.1 内核加载流程
```
// kernel/bpf/syscall.c
static int bpf_prog_load(union bpf_attr *attr, bpfptr_t uattr)
{
    // 1. 创建 BPF 程序对象
    prog = bpf_prog_alloc(...);
    
    // 2. 验证程序
    err = bpf_check(&prog, attr, uattr);
    
    // 3. JIT 编译
    bpf_prog_select_runtime(prog, &err);
    
    // 4. 添加到符号表
    bpf_prog_kallsyms_add(prog);
    
    // 5. 注册到 perf 子系统？ ← 与用户态程序的 PERF_EVENT_IOC_SET_BPF 调用互补
    perf_event_bpf_event(prog, PERF_BPF_EVENT_PROG_LOAD, 0);

    // 6. 返回文件描述符
    return bpf_prog_new_fd(prog, ...);
}

// 返回 bpf_prog 对应的fd
__sys_bpf // BPF_PROG_LOAD
 bpf_prog_load
  bpf_prog_size // 计算可以包含所有指令的 bpf_prog 需要多大空间
  bpf_prog_alloc // 分配 bpf_prog
  copy_from_bpfptr // 将指令从 attr->insns 拷贝到 prog->insns
  bpf_check // 校验 bpf 程序
  bpf_prog_select_runtime
   bpf_int_jit_compile // 不同的CPU架构有各自的实现
    do_jit
  bpf_prog_alloc_id // 插入全局的 prog_idr 树，并获取对应的id
  bpf_prog_kallsyms_add
   bpf_prog_ksym_set_addr // 根据 bpf_func 在 ksym 上设置二进制的地址
    // start = prog->bpf_func
	// end = addr + hdr->pages * PAGE_SIZE
   bpf_prog_ksym_set_name // 设置二进制的名字
   bpf_ksym_add // 将二进制添加到全局链表 bpf_kallsyms 和全局树 bpf_tree
  perf_event_bpf_event // 注册到 perf 子系统
  bpf_prog_new_fd
   anon_inode_getfd
    anon_inode_getfile // 为匿名inode分配 file
	fd_install // 关联 file 与 fd

```

#### 2.2.2 指令解析
> 已知：<br>
> bpf程序是以什么形式传递给内核的？<br>
>     —— 通过一系列的bpf_insn指令
>
> bpf_insn指令怎么来的？<br>
>     —— 由clang将C语言指令编译而来
>
> 分析：<br>
> bpf_insn指令什么含义？<br>
> bpf_insn指令怎么和C语言程序对应？

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

```
内核指令格式定义：
struct bpf_insn {
	__u8	code;		/* opcode */
	__u8	dst_reg:4;	/* dest register */
	__u8	src_reg:4;	/* source register */
	__s16	off;		/* signed offset */
	__s32	imm;		/* signed immediate constant */
};


偏移    二进制数据 (每条指令 8 字节，对应 struct bpf_insn)
────────────────────────────────────────────────────────────

0x40:   bf | a6 | 00 00 | 00 00 00 00          指令 0
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000 (立即数)
         │    │     └─ off = 0x0000 (偏移量)
         │    └─ regs = 0xa6
         │       dst_reg = 0xa6 & 0x0f = 6 (低4位)
         │       src_reg = 0xa6 >> 4 = 10 (高4位)
         └─ code = 0xbf (BPF_ALU64 | BPF_X | BPF_MOV)
        
        解析结果：
        struct bpf_insn {
            .code = 0xbf,      // MOV 操作，64位，寄存器模式
            .dst_reg = 6,      // 目标寄存器 r6
            .src_reg = 10,     // 源寄存器 r10 (栈指针)
            .off = 0,
            .imm = 0
        }
        语义：r6 = r10  (保存栈指针)

内核中相关操作码定义如下：
#define BPF_ALU64       0x07    /* alu mode in double word width */
#define         BPF_X           0x08
#define BPF_MOV         0xb0    /* mov reg to reg */
所以 0xbf 对应(BPF_ALU64 | BPF_X | BPF_MOV)，其他指令类似

0x48:   07 | 06 | 00 00 | f0 ff ff ff          指令 1
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0xfffffff0 (补码表示 -16)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x06
         │       dst_reg = 0x06 & 0x0f = 6
         │       src_reg = 0x06 >> 4 = 0
         └─ code = 0x07 (BPF_ALU64 | BPF_K | BPF_ADD)
        
        解析结果：
        struct bpf_insn {
            .code = 0x07,      // ADD 操作，64位，立即数模式
            .dst_reg = 6,
            .src_reg = 0,
            .off = 0,
            .imm = -16
        }
        语义：r6 += -16  (在栈上分配 16 字节空间)


0x50:   bf | 61 | 00 00 | 00 00 00 00          指令 2
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0x61
         │       dst_reg = 0x61 & 0x0f = 1
         │       src_reg = 0x61 >> 4 = 6
         └─ code = 0xbf (BPF_ALU64 | BPF_X | BPF_MOV)
        
        语义：r1 = r6  (r1 指向栈空间，作为函数参数)


0x58:   b7 | 02 | 00 00 | 10 00 00 00          指令 3
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000010 (16)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x02
         │       dst_reg = 0x02 & 0x0f = 2
         │       src_reg = 0x02 >> 4 = 0
         └─ code = 0xb7 (BPF_ALU64 | BPF_K | BPF_MOV)
        
        语义：r2 = 16  (字符串缓冲区大小)


0x60:   85 | 00 | 00 00 | 10 00 00 00          指令 4
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000010 (helper 函数 ID = 16)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x00
         │       dst_reg = 0, src_reg = 0
         └─ code = 0x85 (BPF_JMP | BPF_CALL)
        
        语义：call 16  (调用 bpf_get_current_comm helper)

内核中相关函数定义如下：
enum bpf_func_id {
	__BPF_FUNC_MAPPER(__BPF_ENUM_FN)
	__BPF_FUNC_MAX_ID,
};

#define __BPF_FUNC_MAPPER(FN)		\
	FN(unspec),			\
	FN(map_lookup_elem),		\
	FN(map_update_elem),		\
	FN(map_delete_elem),		\
	FN(probe_read),			\
	FN(ktime_get_ns),		\
	FN(trace_printk),		\
	FN(get_prandom_u32),		\
	FN(get_smp_processor_id),	\
	FN(skb_store_bytes),		\
	FN(l3_csum_replace),		\
	FN(l4_csum_replace),		\
	FN(tail_call),			\
	FN(clone_redirect),		\
	FN(get_current_pid_tgid),	\
	FN(get_current_uid_gid),	\
	FN(get_current_comm),		\
	FN(get_cgroup_classid),		\
...
可知 get_current_comm 对应的函数编号为16，其他函数类似


0x68:   b7 | 01 | 00 00 | 25 73 00 00          指令 5
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00007325 (ASCII: 小端序 "%s\0\0")
         │    │     └─ off = 0x0000
         │    └─ regs = 0x01
         │       dst_reg = 1, src_reg = 0
         └─ code = 0xb7 (BPF_ALU64 | BPF_K | BPF_MOV)
        
        语义：r1 = 0x7325  (准备写入 "%s")


0x70:   6b | 1a | e8 ff | 00 00 00 00          指令 6
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0xffe8 (补码表示 -24)
         │    └─ regs = 0x1a
         │       dst_reg = 0x1a & 0x0f = 10
         │       src_reg = 0x1a >> 4 = 1
         └─ code = 0x6b (BPF_STX | BPF_H | BPF_MEM)
                        (STX=寄存器存储, H=halfword 2字节, MEM=内存)
        
        语义：*(u16 *)(r10 - 24) = r1  (写入 "%s" 到栈上)


0x78:   18 | 01 | 00 00 | 65 78 65 63          指令 7 (宽指令第1部分)
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm 低32位 = 0x63657865 (小端序 "exec")
         │    │     └─ off = 0x0000
         │    └─ regs = 0x01
         │       dst_reg = 1, src_reg = 0
         └─ code = 0x18 (BPF_LD | BPF_DW | BPF_IMM)
                        (LD=load, DW=double word 64位, IMM=立即数)

0x80:   00 | 00 | 00 00 | 76 65 3a 20          指令 7 (宽指令第2部分)
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm 高32位 = 0x203a6576 (小端序 "ve: ")
         │    │     └─ off = 0x0000
         │    └─ regs = 0x00
         └─ code = 0x00 (宽指令的延续标记)
        
        完整语义：r1 = 0x203a657665786365  (64位立即数，小端序 "execve: ")


0x88:   7b | 1a | e0 ff | 00 00 00 00          指令 8
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0xffe0 (补码表示 -32)
         │    └─ regs = 0x1a
         │       dst_reg = 10, src_reg = 1
         └─ code = 0x7b (BPF_STX | BPF_DW | BPF_MEM)
                        (DW=double word 8字节)
        
        语义：*(u64 *)(r10 - 32) = r1  (写入 "execve: " 到栈上)


0x90:   b7 | 01 | 00 00 | 00 00 00 00          指令 9
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0x01
         │       dst_reg = 1, src_reg = 0
         └─ code = 0xb7 (BPF_ALU64 | BPF_K | BPF_MOV)
        
        语义：r1 = 0


0x98:   73 | 1a | ea ff | 00 00 00 00          指令 10
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0xffea (补码表示 -22)
         │    └─ regs = 0x1a
         │       dst_reg = 10, src_reg = 1
         └─ code = 0x73 (BPF_STX | BPF_B | BPF_MEM)
                        (B=byte 1字节)
        
        语义：*(u8 *)(r10 - 22) = r1  (写入 '\0' 字符串结束符)


0xa0:   bf | a1 | 00 00 | 00 00 00 00          指令 11
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0xa1
         │       dst_reg = 0xa1 & 0x0f = 1
         │       src_reg = 0xa1 >> 4 = 10
         └─ code = 0xbf (BPF_ALU64 | BPF_X | BPF_MOV)
        
        语义：r1 = r10


0xa8:   07 | 01 | 00 00 | e0 ff ff ff          指令 12
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0xffffffe0 (补码表示 -32)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x01
         │       dst_reg = 1, src_reg = 0
         └─ code = 0x07 (BPF_ALU64 | BPF_K | BPF_ADD)
        
        语义：r1 += -32  (r1 指向格式化字符串起始位置)


0xb0:   b7 | 02 | 00 00 | 0b 00 00 00          指令 13
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x0000000b (11)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x02
         │       dst_reg = 2, src_reg = 0
         └─ code = 0xb7 (BPF_ALU64 | BPF_K | BPF_MOV)
        
        语义：r2 = 11  (字符串长度)


0xb8:   bf | 63 | 00 00 | 00 00 00 00          指令 14
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0x63
         │       dst_reg = 0x63 & 0x0f = 3
         │       src_reg = 0x63 >> 4 = 6
         └─ code = 0xbf (BPF_ALU64 | BPF_X | BPF_MOV)
        
        语义：r3 = r6  (r3 指向进程名缓冲区)


0xc0:   85 | 00 | 00 00 | 06 00 00 00          指令 15
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000006 (helper 函数 ID = 6)
         │    │     └─ off = 0x0000
         │    └─ regs = 0x00
         │       dst_reg = 0, src_reg = 0
         └─ code = 0x85 (BPF_JMP | BPF_CALL)
        
        语义：call 6  (调用 bpf_trace_printk helper)

trace_printk 对应的函数编号是6


0xc8:   b7 | 00 | 00 00 | 00 00 00 00          指令 16
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0x00
         │       dst_reg = 0, src_reg = 0
         └─ code = 0xb7 (BPF_ALU64 | BPF_K | BPF_MOV)
        
        语义：r0 = 0  (设置返回值)


0xd0:   95 | 00 | 00 00 | 00 00 00 00          指令 17
        ─┬   ─┬   ──┬──   ─────┬─────
         │    │     │          └─ imm = 0x00000000
         │    │     └─ off = 0x0000
         │    └─ regs = 0x00
         │       dst_reg = 0, src_reg = 0
         └─ code = 0x95 (BPF_JMP | BPF_EXIT)
        
        语义：exit  (程序退出)
```







