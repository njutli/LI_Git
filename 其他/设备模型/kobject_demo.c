#include <linux/module.h>
#include <linux/kobject.h>
#include <linux/sysfs.h>
#include <linux/init.h>

static struct kobject *demo_kobj;   // 指向本模块的 kobject

// 属性文件的 show/store 回调
static ssize_t demo_value_show(struct kobject *kobj,
                               struct kobj_attribute *attr, char *buf)
{
    return sprintf(buf, "Hello from kobject_demo!\n");
}

static ssize_t demo_value_store(struct kobject *kobj,
                                struct kobj_attribute *attr,
                                const char *buf, size_t count)
{
    pr_info("kobject_demo: received input: %.*s\n", (int)count, buf);
    return count;
}

// 定义 sysfs 属性
static struct kobj_attribute demo_attr =
    __ATTR(demo_value, 0664, demo_value_show, demo_value_store);

// 模块加载时创建 kobject 并添加 sysfs 节点
static int __init kobject_demo_init(void)
{
    int ret;

    // 创建一个 /sys/kernel/kobject_demo 目录
    demo_kobj = kobject_create_and_add("kobject_demo", kernel_kobj);
    if (!demo_kobj)
        return -ENOMEM;

    // 创建属性文件 demo_value
    ret = sysfs_create_file(demo_kobj, &demo_attr.attr);
    if (ret)
        kobject_put(demo_kobj);

    pr_info("kobject_demo: module loaded\n");
    return ret;
}

// 模块卸载时清理
static void __exit kobject_demo_exit(void)
{
    sysfs_remove_file(demo_kobj, &demo_attr.attr);
    kobject_put(demo_kobj);
    pr_info("kobject_demo: module unloaded\n");
}

module_init(kobject_demo_init);
module_exit(kobject_demo_exit);

MODULE_LICENSE("GPL");
MODULE_AUTHOR("Example");
MODULE_DESCRIPTION("Simple kobject demo module");
