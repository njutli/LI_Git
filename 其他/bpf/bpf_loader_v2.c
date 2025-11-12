#define _GNU_SOURCE
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <fcntl.h>
#include <errno.h>
#include <sys/resource.h>
#include <sys/syscall.h>
#include <sys/utsname.h>
#include <sys/ioctl.h>
#include <linux/bpf.h>
#include <linux/perf_event.h>
#include <pthread.h>
#include <elf.h>

#define LOG_BUF_SIZE 65536

static inline long sys_bpf(int cmd, union bpf_attr *attr, unsigned int size) {
    return syscall(__NR_bpf, cmd, attr, size);
}

static inline int sys_perf_event_open(struct perf_event_attr *attr,
                                       pid_t pid, int cpu, int group_fd,
                                       unsigned long flags) {
    return syscall(__NR_perf_event_open, attr, pid, cpu, group_fd, flags);
}

// 线程函数：持续读取 trace_pipe
void *read_trace_pipe(void *arg) {
    FILE *fp = fopen("/sys/kernel/debug/tracing/trace_pipe", "r");
    if (!fp) {
        perror("Failed to open trace_pipe");
        return NULL;
    }

    char line[256];
    printf("\n=== BPF Trace Output ===\n");
    while (fgets(line, sizeof(line), fp)) {
        printf("%s", line);
        fflush(stdout);
    }

    fclose(fp);
    return NULL;
}

int main(int argc, char **argv) {
    // 提升内存限制
    struct rlimit rlim = {RLIM_INFINITY, RLIM_INFINITY};
    setrlimit(RLIMIT_MEMLOCK, &rlim);

    if (argc < 2) {
        fprintf(stderr, "Usage: %s <bpf_object.o>\n", argv[0]);
        return 1;
    }

    printf("=== BPF Loader with Live Trace ===\n\n");

    // 1. 加载 BPF 对象文件
    printf("[1] Loading %s\n", argv[1]);

    FILE *fp = fopen(argv[1], "rb");
    if (!fp) {
        perror("fopen");
        return 1;
    }

    fseek(fp, 0, SEEK_END);
    size_t file_size = ftell(fp);
    fseek(fp, 0, SEEK_SET);

    unsigned char *obj_buf = malloc(file_size);
    fread(obj_buf, 1, file_size, fp);
    fclose(fp);

    // 解析 ELF
    Elf64_Ehdr *ehdr = (Elf64_Ehdr *)obj_buf;
    Elf64_Shdr *shdr = (Elf64_Shdr *)(obj_buf + ehdr->e_shoff);

    // 查找字符串表
    Elf64_Shdr *shstrtab = &shdr[ehdr->e_shstrndx];
    char *shstrtab_data = (char *)(obj_buf + shstrtab->sh_offset);

    // 查找 tracepoint/syscalls/sys_enter_execve section
    unsigned char *insns = NULL;
    size_t insns_cnt = 0;
    char *license = "GPL";

    for (int i = 0; i < ehdr->e_shnum; i++) {
        char *sec_name = shstrtab_data + shdr[i].sh_name;

        if (strcmp(sec_name, "tracepoint/syscalls/sys_enter_execve") == 0) {
            insns = obj_buf + shdr[i].sh_offset;
            insns_cnt = shdr[i].sh_size / 8;  // 每条指令 8 字节
            printf("Found program section: %s\n", sec_name);
            printf("  Offset: 0x%lx\n", shdr[i].sh_offset);
            printf("  Size: %lu bytes\n", shdr[i].sh_size);
            printf("  Instructions: %zu\n", insns_cnt);
        } else if (strcmp(sec_name, "license") == 0) {
            license = (char *)(obj_buf + shdr[i].sh_offset);
            printf("Found license: %s\n", license);
        }
    }

    if (!insns) {
        fprintf(stderr, "Error: No BPF program section found\n");
        return 1;
    }

    printf("\n");

    // 2. 加载到内核
    printf("[2] Loading into kernel...\n");

    union bpf_attr load_attr;
    memset(&load_attr, 0, sizeof(load_attr));

    load_attr.prog_type = BPF_PROG_TYPE_TRACEPOINT;
    load_attr.insns = (unsigned long)insns;
    load_attr.insn_cnt = insns_cnt;
    load_attr.license = (unsigned long)license;

    struct utsname uts;
    uname(&uts);
    unsigned version;
    sscanf(uts.release, "%u", &version);
    load_attr.kern_version = version << 16;

    char log_buf[LOG_BUF_SIZE];
    load_attr.log_buf = (unsigned long)log_buf;
    load_attr.log_size = LOG_BUF_SIZE;
    load_attr.log_level = 1;

    int prog_fd = sys_bpf(BPF_PROG_LOAD, &load_attr, sizeof(load_attr));
    if (prog_fd < 0) {
        perror("BPF_PROG_LOAD");
        printf("Verifier log:\n%s\n", log_buf);
        return 1;
    }

    printf("SUCCESS! Program loaded, fd=%d\n\n", prog_fd);

    // 3. 获取 tracepoint ID
    printf("[3] Attaching to tracepoint...\n");

    FILE *id_fp = fopen("/sys/kernel/debug/tracing/events/syscalls/sys_enter_execve/id", "r");
    if (!id_fp) {
        perror("fopen tracepoint id");
        return 1;
    }

    int tp_id;
    fscanf(id_fp, "%d", &tp_id);
    fclose(id_fp);

    printf("Tracepoint ID: %d\n", tp_id);

    // 4. 附加到 perf event
    struct perf_event_attr pe_attr;
    memset(&pe_attr, 0, sizeof(pe_attr));

    pe_attr.type = PERF_TYPE_TRACEPOINT;
    pe_attr.size = sizeof(pe_attr);
    pe_attr.config = tp_id;
    pe_attr.sample_period = 1;
    pe_attr.wakeup_events = 1;

    int event_fd = sys_perf_event_open(&pe_attr, -1, 0, -1, 0);
    if (event_fd < 0) {
        perror("perf_event_open");
        return 1;
    }

    if (ioctl(event_fd, PERF_EVENT_IOC_SET_BPF, prog_fd) < 0) {
        perror("ioctl PERF_EVENT_IOC_SET_BPF");
        return 1;
    }
/*
    if (ioctl(event_fd, PERF_EVENT_IOC_ENABLE, 0) < 0) {
        perror("ioctl PERF_EVENT_IOC_ENABLE");
        return 1;
    }
*/
    printf("Attached successfully!\n\n");

    // 5. 启动线程读取 trace_pipe
    pthread_t trace_thread;
    pthread_create(&trace_thread, NULL, read_trace_pipe, NULL);

    // 6. 主线程等待
    printf("Monitoring execve calls... (Press Ctrl+C to stop)\n");
    printf("Trace output will appear below:\n");
    printf("────────────────────────────────────────\n\n");

    pthread_join(trace_thread, NULL);

    close(event_fd);
    close(prog_fd);
    free(obj_buf);

    return 0;
}
