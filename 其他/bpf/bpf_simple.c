#include <linux/bpf.h>
#include <bpf/bpf_helpers.h>

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
