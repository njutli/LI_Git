《General-Purpose Graphics Processor Architecture》<br>
https://zhuanlan.zhihu.com/p/510690054

内核开发指导<br>
https://docs.kernel.org/gpu/drm-internals.html#driver-initialization

# 整体结构
```
[User Space]  
  │  
  ├─ UMD (CUDA/ROCm)  
  │    │  
  │    └─ → [Kernel Space]  
  │           │  
  │           ├─ KMD (DRM/KMS)  
  │           │    │  
  │           │    └─ → [Hardware]  
  │           │         ├─ GPU MMU  
  │           │         ├─ SMMU  
  │           │         └─ PCIe/DRAM Controller  
```
https://blog.csdn.net/xiaoheshang_123/article/details/147374247

https://zhuanlan.zhihu.com/p/688902157

# CUDA框架

https://yuanheci.site/archives/gpu%E8%BD%AF%E4%BB%B6%E6%A0%88

- 开发库 (Libraries)：它是基于 CUDA 技术所提供的应用开发库。CUDA 包含两个重要的标准数学运算库——CUFFT（离散快速傅立叶变换）和 CUBLAS（基本线性代数子程序库）。这两个数学运算库解决的是典型的大规模的并行计算问题，也是在密集数据计算中非常常见的计算类型。
- 运行时 (Runtime)：提供了应用开发接口和运行期组件，包括基本数据类型的定义和各类计算、类型转换、内存管理、设备访问和执行调度等函数。例如：
运行时 API：提供了一系列函数，用于执行设备内存分配、内核启动、事件管理、流控制等操作。
核函数：这是在 GPU 上并行执行的函数，由开发者编写，用于执行具体的并行计算任务。
- 驱动 (Driver)：驱动程序层位于硬件层之上，是 CUDA 架构中的中间件，负责硬件抽象、资源管理、错误处理、CPU 和 GPU 通信。驱动程序层通过 CUDA 运行时 API 与主机代码交互。
![CUDA结构](https://yuanheci.site/upload/2025/05/image.png)
![CUDA](https://i-blog.csdnimg.cn/direct/c7d931db0d7946e9a9bc2c69835f3a88.png)

# MUSA架构




