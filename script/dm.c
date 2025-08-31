// dmsetup create linear_1 --table "0 2097152 linear /dev/sdc 0"

#include <stdio.h>
#include <stdlib.h>
#include <fcntl.h>
#include <unistd.h>
#include <string.h>
#include <errno.h>
#include <linux/dm-ioctl.h>
#include <sys/ioctl.h>

#define DM_EXISTS_FLAG 0x00000004

int main(int argc, char *argv[]) {
    int ret, fd, index;
	int in_data;

    struct dm_ioctl *param = calloc(1, sizeof(struct dm_ioctl) + 16384);
    struct dm_target_spec sp;
    char *target = (char *)(param + 1); // 跳过 dm_ioctl，指向后面的 16384 大小区域
    char *last_param = target + sizeof(struct dm_target_spec);
	char *msg_last_param = target + sizeof(struct dm_target_msg);

    struct dm_target_msg tmsg;


    fd = open("/dev/mapper/control", O_RDWR);
    if (fd < 0) {
        printf("Failed to open device /dev/mapper/control: %s\n", strerror(errno));
        return -1;
    }

    // DM_VERSION
    param->version[0] = 4;
    param->version[1] = 0;
    param->version[2] = 0;
    param->data_size = 16384;
    param->flags = DM_EXISTS_FLAG;

    ret = ioctl(fd, DM_VERSION, param);
    if (ret < 0) {
        printf("Failed to send DM_VERSION: %s\n", strerror(errno));
        close(fd);
        return -1;
    }
    printf("DM_VERSION %d %d %d\n", param->version[0], param->version[1], param->version[2]);

	// DM_TARGET_MSG
	param->data_start = 312;
	strncpy(param->name, "pool", sizeof(param->name));

	tmsg.sector = 0;
	memcpy(target, &tmsg, sizeof(struct dm_target_msg));

	strcpy(msg_last_param, "create_thin 0");

    ret = ioctl(fd, DM_TARGET_MSG, param);
    if (ret < 0) {
        printf("Failed to send DM_TARGET_MSG: %s\n", strerror(errno));
        close(fd);
        return -1;
    }

	// clear
	memset(target, 0, 16384);

    // DM_DEV_CREATE
	param->data_size = 16384;
	param->dev = 0;
	param->flags = DM_EXISTS_FLAG;
    strncpy(param->name, "thin", sizeof(param->name));

    ret = ioctl(fd, DM_DEV_CREATE, param);
    if (ret < 0) {
        printf("Failed to send DM_DEV_CREATE: %s\n", strerror(errno));
        close(fd);
        return -1;
    }

    // DM_TABLE_LOAD
    memset(param->name, 0, sizeof(param->name));
    param->flags = DM_EXISTS_FLAG;
    param->data_start = 312;
    param->data_size = 16384;
    //param->dev = 64514; // makedev(252, 2)
    param->target_count = 1;

    sp.sector_start = 0;
    sp.length = 14680064;
    strncpy(sp.target_type, "thin", sizeof(sp.target_type) - 1);
    sp.target_type[sizeof(sp.target_type) - 1] = '\0';
    
    memcpy(target, &sp, sizeof(struct dm_target_spec));
    strcpy(last_param, "/dev/mapper/pool 0");

    ret = ioctl(fd, DM_TABLE_LOAD, param);
    if (ret < 0) {
        printf("Failed to send DM_TABLE_LOAD: %s\n", strerror(errno));
        close(fd);
        return -1;
    }

/*
    // DM_DEV_SUSPEND
    param->flags = DM_SUSPEND_FLAG; // trigger deadlock
    param->event_nr = 6345265;
    param->data_size = 16384;
    param->dev = 0;
    strncpy(param->name, "linear_1", sizeof(param->name));

    ret = ioctl(fd, DM_DEV_SUSPEND, param);
    if (ret < 0) {
        printf("Failed to send DM_DEV_SUSPEND: %s\n", strerror(errno));
        close(fd);
        return -1;
    }
*/
    // DM_DEV_SUSPEND
    param->flags = DM_EXISTS_FLAG; // continue creating
    param->event_nr = 6345265;
    param->data_size = 16384;
    param->dev = 0;
    strncpy(param->name, "thin", sizeof(param->name));

    ret = ioctl(fd, DM_DEV_SUSPEND, param);
    if (ret < 0) {
        printf("Failed to send DM_DEV_SUSPEND: %s\n", strerror(errno));
        close(fd);
        return -1;
    }
    printf("DM_DEV_SUSPEND sent successfully\n");
    close(fd);

    return 0;
}
