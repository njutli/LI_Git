> tracepoint ID怎么设置的？
> "/sys/kernel/debug/tracing/events/syscalls/sys_enter_execve/id" 这个文件怎么来的？

```
static const struct file_operations ftrace_event_id_fops = {
	.read = event_id_read,
	.llseek = default_llseek,
};

event_id_read
 event_file_data
  READ_ONCE(file_inode(filp)->i_private) // 读取的结果来自 i_private


[2025-11-10 20:31:09]  [    4.153295][    T1] event_create_dir debug sys_enter_execve
[2025-11-10 20:31:09]  [    4.154578][    T1] CPU: 9 PID: 1 Comm: swapper/0 Not tainted 5.10.0-01809-g13617f27bdfa-dirty #255
[2025-11-10 20:31:09]  [    4.155568][    T1] Hardware name: QEMU Standard PC (i440FX + PIIX, 1996), BIOS 1.16.3-2.fc40 04/01/2014
[2025-11-10 20:31:09]  [    4.155568][    T1] Call Trace:
[2025-11-10 20:31:09]  [    4.155568][    T1]  dump_stack+0x77/0x9b
[2025-11-10 20:31:09]  [    4.155568][    T1]  event_create_dir.cold+0x65/0x8d
[2025-11-10 20:31:09]  [    4.155568][    T1]  __trace_early_add_event_dirs+0x29/0x50
[2025-11-10 20:31:09]  [    4.155568][    T1]  event_trace_init+0x9a/0xe9
[2025-11-10 20:31:09]  [    4.155568][    T1]  tracer_init_tracefs+0x73/0x2ab
[2025-11-10 20:31:09]  [    4.155568][    T1]  ? tracer_alloc_buffers.isra.0+0x3a3/0x3a3
[2025-11-10 20:31:09]  [    4.155568][    T1]  do_one_initcall+0x5b/0x240
[2025-11-10 20:31:09]  [    4.155568][    T1]  do_initcall_level+0x134/0x141
[2025-11-10 20:31:09]  [    4.155568][    T1]  do_initcalls+0x64/0x76
[2025-11-10 20:31:09]  [    4.155568][    T1]  kernel_init_freeable+0x377/0x3b2
[2025-11-10 20:31:09]  [    4.155568][    T1]  ? rest_init+0x352/0x352
[2025-11-10 20:31:09]  [    4.155568][    T1]  kernel_init+0xb/0x12e
[2025-11-10 20:31:09]  [    4.155568][    T1]  ? rest_init+0x352/0x352
[2025-11-10 20:31:09]  [    4.155568][    T1]  ret_from_fork+0x22/0x30


tracer_init_tracefs
 event_trace_init
  top_trace_array // 获取 trace_array
  early_event_add_tracer
   __trace_early_add_event_dirs
    list_for_each_entry // 遍历 &tr->events 获取 trace_event_file 处理
    event_create_dir
     trace_create_file // "id" 对应ops为 ftrace_event_id_fops
      tracefs_create_file
       inode->i_private // 设置 i_private ，来源为 call->event.type
```

> call->event.type 的来源又是什么

```
[2025-11-11 11:05:47]  [    0.626434][    T0] trace_event_raw_init sys_enter_execve
[2025-11-11 11:05:47]  [    0.626438][    T0] CPU: 0 PID: 0 Comm: swapper/0 Not tainted 5.10.0-01809-g13617f27bdfa-dirty #257
[2025-11-11 11:05:47]  [    0.626441][    T0] Hardware name: QEMU Standard PC (i440FX + PIIX, 1996), BIOS 1.16.3-2.fc40 04/01/2014
[2025-11-11 11:05:47]  [    0.626444][    T0] Call Trace:
[2025-11-11 11:05:47]  [    0.626452][    T0]  dump_stack+0x77/0x9b
[2025-11-11 11:05:47]  [    0.626458][    T0]  trace_event_raw_init+0x34/0x4b
[2025-11-11 11:05:47]  [    0.626463][    T0]  init_syscall_trace+0x4c/0x6e
[2025-11-11 11:05:47]  [    0.626469][    T0]  event_init+0x2e/0x60
[2025-11-11 11:05:47]  [    0.626472][    T0]  trace_event_init+0x9f/0x135
[2025-11-11 11:05:47]  [    0.626478][    T0]  start_kernel+0x243/0x4c1
[2025-11-11 11:05:47]  [    0.626483][    T0]  secondary_startup_64_no_verify+0xc3/0xcb

trace_event_init
 event_trace_enable
  top_trace_array // 获取 trace_array
  event_init
   init_syscall_trace // call->class->raw_init
    trace_event_raw_init
	 register_trace_event
	  event->type = next_event_type++;
  list_add // 将 trace_event_call 加入 ftrace_events 链表
  __trace_early_add_events
   list_for_each_entry // 遍历 ftrace_events 链表处理 trace_event_call
   __trace_early_add_new_event
    trace_create_new_event
	 list_add // 将绑定了 trace_event_call 的 trace_event_file 加入 &tr->events 链表

```

> trace_event_call 是怎么来的
```
SYSCALL_DEFINE3(execve,...

SYSCALL_DEFINEx
 SYSCALL_METADATA
  SYSCALL_TRACE_ENTER_EVENT
#define SYSCALL_TRACE_ENTER_EVENT(sname)                                \
        static struct syscall_metadata __syscall_meta_##sname;          \
        static struct trace_event_call __used                           \
          event_enter_##sname = {                                       \  // 定义 trace_event_call event_enter_execve
                .class                  = &event_class_syscall_enter,   \
                {                                                       \
                        .name                   = "sys_enter"#sname,    \ // name 是 sys_enter_execve
                },                                                      \
                .event.funcs            = &enter_syscall_print_funcs,   \
                .data                   = (void *)&__syscall_meta_##sname,\
                .flags                  = TRACE_EVENT_FL_CAP_ANY,       \
        };                                                              \
        static struct trace_event_call __used                           \
          __section("_ftrace_events")                                   \ // 放入 _ftrace_events 段
         *__event_enter_##sname = &event_enter_##sname;

```
