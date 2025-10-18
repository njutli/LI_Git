// io_uring fixed buffers
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <fcntl.h>
#include <unistd.h>
#include <liburing.h>

#define QUEUE_DEPTH 4      // 环形队列深度
#define BUF_COUNT 2        // 缓冲区数量
#define BUF_SIZE 4096      // 每个缓冲区大小
#define TEST_FILE "io_uring_test.txt"

int main() {
    struct io_uring ring;
    int fd;
    char *buffers[BUF_COUNT];
    struct iovec iovs[BUF_COUNT];
    const char *test_data = "Hello io_uring with fixed buffers!\n";
    size_t data_len = strlen(test_data);

    // 1. 初始化io_uring（仅使用io_uring_queue_init）
    if (io_uring_queue_init(QUEUE_DEPTH, &ring, 0) < 0) {
        perror("io_uring_queue_init");
        return 1;
    }

    // 2. 分配对齐的缓冲区
    for (int i = 0; i < BUF_COUNT; i++) {
        if (posix_memalign((void**)&buffers[i], BUF_SIZE, BUF_SIZE)) {
            perror("posix_memalign");
            return 1;
        }
        memset(buffers[i], 0, BUF_SIZE);
    }

    // 3. 注册缓冲区池（仅使用io_uring_register_buffers）
    for (int i = 0; i < BUF_COUNT; i++) {
        iovs[i].iov_base = buffers[i];
        iovs[i].iov_len = BUF_SIZE;
    }
    if (io_uring_register_buffers(&ring, iovs, BUF_COUNT) < 0) {
        perror("io_uring_register_buffers");
        return 1;
    }

    // 4. 准备测试数据
    memcpy(buffers[0], test_data, data_len);

    // 5. 打开测试文件
    if ((fd = open(TEST_FILE, O_RDWR | O_CREAT | O_TRUNC, 0644)) < 0) {
        perror("open");
        return 1;
    }

    // ========== 第一阶段：写入文件 ==========
    struct io_uring_sqe *sqe = io_uring_get_sqe(&ring);  // 获取SQE
    io_uring_prep_write_fixed(  // 准备写操作
        sqe,
        fd,
        buffers[0],   // 源缓冲区（索引0）
        data_len,
        0,            // 文件偏移量
        0             // 缓冲区索引
    );
    sqe->user_data = 1;  // 标识写操作
    io_uring_submit(&ring);  // 提交操作

    // 等待写入完成
    struct io_uring_cqe *cqe;
    io_uring_wait_cqe(&ring, &cqe);  // 等待CQE
    if (cqe->res < 0) {
        fprintf(stderr, "写错误: %s\n", strerror(-cqe->res));
        return 1;
    }
    printf("写入成功: %d字节\n", cqe->res);
    io_uring_cqe_seen(&ring, cqe);  // 标记CQE已处理

    // ========== 第二阶段：读取文件 ==========
    sqe = io_uring_get_sqe(&ring);  // 获取新SQE
    io_uring_prep_read_fixed(  // 准备读操作
        sqe,
        fd,
        buffers[1],   // 目标缓冲区（索引1）
        BUF_SIZE,
        0,            // 文件偏移量
        1             // 缓冲区索引
    );
    sqe->user_data = 2;  // 标识读操作
    io_uring_submit(&ring);  // 提交操作

    // 等待读取完成
    io_uring_wait_cqe(&ring, &cqe);
    if (cqe->res < 0) {
        fprintf(stderr, "读错误: %s\n", strerror(-cqe->res));
        return 1;
    }
    printf("读取成功: %d字节\n", cqe->res);

    // 6. 验证并打印缓冲区内容
    printf("\n===== 缓冲区内容对比 =====\n");
    printf("写入缓冲区[0]: %.*s\n", (int)data_len, buffers[0]);
    printf("读取缓冲区[1]: %.*s\n", cqe->res, buffers[1]);

    if (cqe->res == data_len &&
        memcmp(buffers[0], buffers[1], data_len) == 0) {
        printf("\n验证成功: 内容匹配\n");
    } else {
        printf("\n验证失败: 内容不匹配 (长度:%d vs %zu)\n",
               cqe->res, data_len);
    }
    io_uring_cqe_seen(&ring, cqe);

    // 7. 注销缓冲区
    if (io_uring_unregister_buffers(&ring) < 0) {
        perror("io_uring_unregister_buffers");
    }

    // 8. 清理资源
    close(fd);
    for (int i = 0; i < BUF_COUNT; i++) {
        free(buffers[i]);
    }
    unlink(TEST_FILE);  // 清理测试文件
    return 0;
}
