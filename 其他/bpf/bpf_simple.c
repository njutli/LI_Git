#include <linux/bpf.h>
#include <bpf/bpf_helpers.h>

/*
SEC() 宏的作用：
告诉编译器：把这个函数放到一个特殊的 ELF section 中，section 名字是 tracepoint/syscalls/sys_enter_execve。
可通过 readelf -S bpf_simple.o 验证
*/
SEC("tracepoint/syscalls/sys_enter_execve")
int trace_execve(void *ctx)
{
    char comm[16];

    // 只获取并打印进程名
    bpf_get_current_comm(&comm, sizeof(comm));

    // 使用 bpf_trace_printk，格式字符串放栈上
    char fmt[] = "execve: %s";
    bpf_trace_printk(fmt, sizeof(fmt), comm);

    return 0;
}

char _license[] SEC("license") = "GPL";
