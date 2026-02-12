<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { Card } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  Loader2,
  Save,
  Edit,
  HardDrive,
  Cpu,
  Database,
  Archive,
  Network,
  MemoryStick,
  Server,
  TrendingUp,
  AlertCircle,
} from "lucide-vue-next";
import {
  useServerResourcesAPI,
  type ServerResourcesResponse,
} from "@/composables/useServerResourcesAPI";
import { useToast } from "vue-toastification";

const toast = useToast();
const { loading, error, getServerResources, updateServerResources } =
  useServerResourcesAPI();

const serverData = ref<ServerResourcesResponse | null>(null);
const showEditDialog = ref(false);
const saving = ref(false);

// Get serverUuid from URL query params (since page is loaded in iframe)
const getServerUuid = (): string => {
  const urlParams = new URLSearchParams(window.location.search);
  const serverUuid = urlParams.get("serverUuid");
  if (serverUuid) {
    return serverUuid;
  }
  // Fallback: try to get from URL path if query param not available
  const pathMatch = window.location.pathname.match(/\/server\/([^\/]+)/);
  return pathMatch ? pathMatch[1] ?? "" : "";
};

const uuidShort = ref<string>(getServerUuid());

const editForm = ref({
  memory: 0,
  cpu: 0,
  disk: 0,
  database_limit: 0,
  backup_limit: 0,
  allocation_limit: 0,
});

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return "0 MB";
  const mb = bytes;
  if (mb >= 1024) {
    return `${(mb / 1024).toFixed(1)} GB`;
  }
  return `${Math.round(mb)} MB`;
};

const formatPercentage = (value: number): string => {
  return `${value}%`;
};

// Check if any resources are in overflow (total or server-specific)
const hasOverflow = computed(() => {
  if (!serverData.value) return false;
  return (
    serverData.value.total_overflow?.has_overflow ||
    serverData.value.server_overflow?.has_overflow ||
    false
  );
});

// Check if this specific server is overflowing
const serverHasOverflow = computed(() => {
  if (!serverData.value) return false;
  return serverData.value.server_overflow?.has_overflow || false;
});

// Check if values are below minimum allowed
const hasInvalidMinimums = computed(() => {
  return (
    editForm.value.memory < 1 ||
    editForm.value.cpu < 1 ||
    editForm.value.disk < 1 ||
    editForm.value.allocation_limit < 1
  );
});

// Check if edit form would cause overflow
const wouldCauseOverflow = computed(() => {
  if (!serverData.value) return false;

  const limits = serverData.value.limits;
  const used = serverData.value.used;
  const currentServer = serverData.value.server.resources;

  // Calculate what total usage would be with new values
  const newMemory = (editForm.value.memory || 0) - currentServer.memory;
  const newCpu = (editForm.value.cpu || 0) - currentServer.cpu;
  const newDisk = (editForm.value.disk || 0) - currentServer.disk;
  const newDb =
    (editForm.value.database_limit || 0) - currentServer.database_limit;
  const newBackup =
    (editForm.value.backup_limit || 0) - currentServer.backup_limit;
  const newAlloc =
    (editForm.value.allocation_limit || 0) - currentServer.allocation_limit;

  return (
    (limits.memory_limit > 0 &&
      used.memory_limit + newMemory > limits.memory_limit) ||
    (limits.cpu_limit > 0 && used.cpu_limit + newCpu > limits.cpu_limit) ||
    (limits.disk_limit > 0 && used.disk_limit + newDisk > limits.disk_limit) ||
    (limits.database_limit > 0 &&
      used.database_limit + newDb > limits.database_limit) ||
    (limits.backup_limit > 0 &&
      used.backup_limit + newBackup > limits.backup_limit) ||
    (limits.allocation_limit > 0 &&
      used.allocation_limit + newAlloc > limits.allocation_limit)
  );
});

// Get overflow details (from total overflow)
const overflowDetails = computed(() => {
  if (!serverData.value || !serverData.value.total_overflow?.overflow_details)
    return [];
  const details: Array<{ resource: string; used: number; limit: number }> = [];
  const overflow = serverData.value.total_overflow.overflow_details;

  const resourceNames: Record<string, string> = {
    memory_limit: "Memory",
    cpu_limit: "CPU",
    disk_limit: "Disk",
    database_limit: "Databases",
    backup_limit: "Backups",
    allocation_limit: "Allocations",
  };

  for (const [key, value] of Object.entries(overflow)) {
    if (value.used !== undefined) {
      details.push({
        resource: resourceNames[key] || key,
        used: value.used,
        limit: value.limit,
      });
    }
  }

  return details;
});

// Get server-specific overflow details
const serverOverflowDetails = computed(() => {
  if (!serverData.value || !serverData.value.server_overflow?.overflow_details)
    return [];
  const details: Array<{
    resource: string;
    server_value: number;
    limit: number;
  }> = [];
  const overflow = serverData.value.server_overflow.overflow_details;

  const resourceNames: Record<string, string> = {
    memory_limit: "Memory",
    cpu_limit: "CPU",
    disk_limit: "Disk",
    database_limit: "Databases",
    backup_limit: "Backups",
    allocation_limit: "Allocations",
  };

  for (const [key, value] of Object.entries(overflow)) {
    if (value.server_value !== undefined) {
      details.push({
        resource: resourceNames[key] || key,
        server_value: value.server_value,
        limit: value.limit,
      });
    }
  }

  return details;
});

const loadServerResources = async () => {
  if (!uuidShort.value) {
    console.error("Server UUID not found in URL");
    toast.error("Server UUID not found");
    return;
  }

  try {
    serverData.value = await getServerResources(uuidShort.value);
    if (serverData.value) {
      editForm.value = {
        memory: serverData.value.server.resources.memory,
        cpu: serverData.value.server.resources.cpu,
        disk: serverData.value.server.resources.disk,
        database_limit: serverData.value.server.resources.database_limit,
        backup_limit: serverData.value.server.resources.backup_limit,
        allocation_limit: serverData.value.server.resources.allocation_limit,
      };
    }
  } catch (err) {
    console.error("Error loading server resources:", err);
    toast.error(error.value || "Failed to load server resources");
  }
};

const openEditDialog = () => {
  if (serverData.value) {
    editForm.value = {
      memory: serverData.value.server.resources.memory,
      cpu: serverData.value.server.resources.cpu,
      disk: serverData.value.server.resources.disk,
      database_limit: serverData.value.server.resources.database_limit,
      backup_limit: serverData.value.server.resources.backup_limit,
      allocation_limit: serverData.value.server.resources.allocation_limit,
    };
  }
  showEditDialog.value = true;
};

const closeEditDialog = () => {
  showEditDialog.value = false;
};

const MIN_MEMORY = 1;
const MIN_CPU = 1;
const MIN_DISK = 1;
const MIN_ALLOCATION = 1;

const saveServerResources = async () => {
  if (wouldCauseOverflow.value) {
    toast.error("Cannot save: This would exceed your resource limits");
    return;
  }

  if (
    editForm.value.memory < MIN_MEMORY ||
    editForm.value.cpu < MIN_CPU ||
    editForm.value.disk < MIN_DISK ||
    editForm.value.allocation_limit < MIN_ALLOCATION
  ) {
    toast.error(
      "Memory, CPU, Disk must be at least 1. Allocation limit must be at least 1."
    );
    return;
  }

  saving.value = true;
  try {
    await updateServerResources(uuidShort.value, editForm.value);
    toast.success("Server resources updated successfully");
    closeEditDialog();
    await loadServerResources();
  } catch (err) {
    const errorMessage =
      err instanceof Error
        ? err.message
        : error.value || "Failed to update server resources";
    toast.error(errorMessage);
  } finally {
    saving.value = false;
  }
};

onMounted(() => {
  loadServerResources();
});
</script>

<template>
  <div class="min-h-screen p-4 md:p-8">
    <div class="max-w-5xl mx-auto space-y-8">
      <!-- Header Section -->
      <div class="text-center space-y-4">
        <div class="flex items-center justify-center gap-3">
          <div class="relative">
            <div
              class="absolute inset-0 bg-primary/20 blur-2xl rounded-full"
            ></div>
            <Server class="relative h-12 w-12 text-primary" />
          </div>
        </div>
        <div>
          <h1
            class="text-5xl font-bold bg-gradient-to-r from-primary to-primary/60 bg-clip-text text-transparent"
          >
            Server Resources
          </h1>
          <p class="text-lg text-muted-foreground mt-2">
            Manage resource allocation for this server within your limits
          </p>
        </div>
      </div>

      <!-- Edit Button -->
      <div v-if="serverData" class="flex justify-center mb-8">
        <Button
          @click="openEditDialog"
          variant="default"
          size="lg"
          :disabled="hasOverflow"
          class="gap-2"
        >
          <Edit class="h-5 w-5" />
          Edit Resources
        </Button>
      </div>
    </div>

    <!-- Loading State -->
    <Card
      v-if="loading && !serverData"
      class="p-8 md:p-10 border-2 shadow-xl bg-card/50 backdrop-blur-sm"
    >
      <div class="flex flex-col items-center justify-center py-12">
        <Loader2 class="h-8 w-8 animate-spin text-primary mb-4" />
        <p class="text-sm text-muted-foreground">Loading server resources...</p>
      </div>
    </Card>

    <!-- Error State -->
    <Card
      v-else-if="error && !loading"
      class="p-8 md:p-10 border-2 border-destructive/50 bg-destructive/5"
    >
      <div class="flex items-center gap-3 mb-4">
        <AlertCircle class="h-6 w-6 text-destructive" />
        <div>
          <h3 class="text-lg font-semibold text-destructive">Error</h3>
          <p class="text-sm text-muted-foreground">{{ error }}</p>
        </div>
      </div>
      <Button @click="loadServerResources" variant="outline">
        <Loader2 class="h-4 w-4 mr-2" />
        Retry
      </Button>
    </Card>

    <!-- Content -->
    <div v-else-if="serverData" class="space-y-6">
      <!-- Server-Specific Overflow Warning -->
      <Card
        v-if="serverHasOverflow"
        class="p-6 border-2 border-red-500/50 bg-red-500/10"
      >
        <div class="flex items-start gap-3">
          <AlertCircle class="h-5 w-5 text-red-400 shrink-0 mt-0.5" />
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-red-400 mb-2">
              This Server Exceeds Your Limits
            </h3>
            <p class="text-sm text-red-300/90 mb-3">
              This server's resource limits exceed your total user limits.
              Please reduce the server's resources.
            </p>
            <div class="space-y-2">
              <div
                v-for="detail in serverOverflowDetails"
                :key="detail.resource"
                class="text-sm text-red-200/80"
              >
                <span class="font-semibold">{{ detail.resource }}:</span>
                {{ detail.server_value }} / {{ detail.limit }} ({{
                  Math.round((detail.server_value / detail.limit) * 100)
                }}%)
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Total Overflow Warning -->
      <Card
        v-if="hasOverflow && !serverHasOverflow"
        class="p-6 border-2 border-red-500/50 bg-red-500/10"
      >
        <div class="flex items-start gap-3">
          <AlertCircle class="h-5 w-5 text-red-400 shrink-0 mt-0.5" />
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-red-400 mb-2">
              Resource Limit Exceeded
            </h3>
            <p class="text-sm text-red-300/90 mb-3">
              Your total resource usage has exceeded your limits. Resource
              editing is disabled until the overflow is resolved.
            </p>
            <div class="space-y-2">
              <div
                v-for="detail in overflowDetails"
                :key="detail.resource"
                class="text-sm text-red-200/80"
              >
                <span class="font-semibold">{{ detail.resource }}:</span>
                {{ detail.used }} / {{ detail.limit }} ({{
                  Math.round((detail.used / detail.limit) * 100)
                }}%)
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Available Resources Summary -->
      <Card class="p-8 md:p-10 border-2 shadow-xl bg-card/50 backdrop-blur-sm">
        <div class="space-y-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-primary/10">
              <TrendingUp class="h-6 w-6 text-primary" />
            </div>
            <div>
              <h2 class="text-2xl font-bold">Available Resources</h2>
              <p class="text-sm text-muted-foreground">
                Your remaining resource capacity
              </p>
            </div>
          </div>
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.memory >
                    serverData.limits.memory_limit &&
                  serverData.limits.memory_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.memory >
                    serverData.limits.memory_limit &&
                  serverData.limits.memory_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <MemoryStick
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.memory >
                      serverData.limits.memory_limit &&
                    serverData.limits.memory_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Memory</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.memory >
                    serverData.limits.memory_limit &&
                  serverData.limits.memory_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ formatBytes(serverData.available.memory_limit) }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.memory >
                    serverData.limits.memory_limit &&
                  serverData.limits.memory_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ formatBytes(serverData.limits.memory_limit) }}
                <span
                  v-if="
                    serverData.server.resources.memory >
                      serverData.limits.memory_limit &&
                    serverData.limits.memory_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server: {{ formatBytes(serverData.server.resources.memory) }}
                </span>
              </div>
            </div>
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.cpu >
                    serverData.limits.cpu_limit &&
                  serverData.limits.cpu_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.cpu >
                    serverData.limits.cpu_limit &&
                  serverData.limits.cpu_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <Cpu
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.cpu >
                      serverData.limits.cpu_limit &&
                    serverData.limits.cpu_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >CPU</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.cpu >
                    serverData.limits.cpu_limit &&
                  serverData.limits.cpu_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ formatPercentage(serverData.available.cpu_limit) }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.cpu >
                    serverData.limits.cpu_limit &&
                  serverData.limits.cpu_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ formatPercentage(serverData.limits.cpu_limit) }}
                <span
                  v-if="
                    serverData.server.resources.cpu >
                      serverData.limits.cpu_limit &&
                    serverData.limits.cpu_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server:
                  {{ formatPercentage(serverData.server.resources.cpu) }}
                </span>
              </div>
            </div>
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.disk >
                    serverData.limits.disk_limit &&
                  serverData.limits.disk_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.disk >
                    serverData.limits.disk_limit &&
                  serverData.limits.disk_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <HardDrive
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.disk >
                      serverData.limits.disk_limit &&
                    serverData.limits.disk_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Disk</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.disk >
                    serverData.limits.disk_limit &&
                  serverData.limits.disk_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ formatBytes(serverData.available.disk_limit) }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.disk >
                    serverData.limits.disk_limit &&
                  serverData.limits.disk_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ formatBytes(serverData.limits.disk_limit) }}
                <span
                  v-if="
                    serverData.server.resources.disk >
                      serverData.limits.disk_limit &&
                    serverData.limits.disk_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server: {{ formatBytes(serverData.server.resources.disk) }}
                </span>
              </div>
            </div>
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.database_limit >
                    serverData.limits.database_limit &&
                  serverData.limits.database_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.database_limit >
                    serverData.limits.database_limit &&
                  serverData.limits.database_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <Database
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.database_limit >
                      serverData.limits.database_limit &&
                    serverData.limits.database_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Databases</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.database_limit >
                    serverData.limits.database_limit &&
                  serverData.limits.database_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ serverData.available.database_limit }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.database_limit >
                    serverData.limits.database_limit &&
                  serverData.limits.database_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ serverData.limits.database_limit }}
                <span
                  v-if="
                    serverData.server.resources.database_limit >
                      serverData.limits.database_limit &&
                    serverData.limits.database_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server: {{ serverData.server.resources.database_limit }}
                </span>
              </div>
            </div>
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.backup_limit >
                    serverData.limits.backup_limit &&
                  serverData.limits.backup_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.backup_limit >
                    serverData.limits.backup_limit &&
                  serverData.limits.backup_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <Archive
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.backup_limit >
                      serverData.limits.backup_limit &&
                    serverData.limits.backup_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Backups</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.backup_limit >
                    serverData.limits.backup_limit &&
                  serverData.limits.backup_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ serverData.available.backup_limit }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.backup_limit >
                    serverData.limits.backup_limit &&
                  serverData.limits.backup_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ serverData.limits.backup_limit }}
                <span
                  v-if="
                    serverData.server.resources.backup_limit >
                      serverData.limits.backup_limit &&
                    serverData.limits.backup_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server: {{ serverData.server.resources.backup_limit }}
                </span>
              </div>
            </div>
            <div
              class="p-4 rounded-lg border transition-colors"
              :class="{
                'bg-red-500/10 border-red-500/50':
                  serverData.server.resources.allocation_limit >
                    serverData.limits.allocation_limit &&
                  serverData.limits.allocation_limit > 0,
                'bg-muted/30 border-border/50': !(
                  serverData.server.resources.allocation_limit >
                    serverData.limits.allocation_limit &&
                  serverData.limits.allocation_limit > 0
                ),
              }"
            >
              <div class="flex items-center gap-2 mb-2">
                <Network
                  class="h-4 w-4"
                  :class="
                    serverData.server.resources.allocation_limit >
                      serverData.limits.allocation_limit &&
                    serverData.limits.allocation_limit > 0
                      ? 'text-red-400'
                      : 'text-primary'
                  "
                />
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Allocations</Label
                >
              </div>
              <div
                class="text-xl font-bold mb-1"
                :class="
                  serverData.server.resources.allocation_limit >
                    serverData.limits.allocation_limit &&
                  serverData.limits.allocation_limit > 0
                    ? 'text-red-400'
                    : ''
                "
              >
                {{ serverData.available.allocation_limit }}
              </div>
              <div
                class="text-xs"
                :class="
                  serverData.server.resources.allocation_limit >
                    serverData.limits.allocation_limit &&
                  serverData.limits.allocation_limit > 0
                    ? 'text-red-300/80'
                    : 'text-muted-foreground'
                "
              >
                of {{ serverData.limits.allocation_limit }}
                <span
                  v-if="
                    serverData.server.resources.allocation_limit >
                      serverData.limits.allocation_limit &&
                    serverData.limits.allocation_limit > 0
                  "
                  class="block text-red-400 font-semibold mt-1"
                >
                  Server: {{ serverData.server.resources.allocation_limit }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Current Server Resources -->
      <Card class="p-8 md:p-10 border-2 shadow-xl bg-card/50 backdrop-blur-sm">
        <div class="space-y-4">
          <div class="flex items-center gap-3 mb-4">
            <div class="p-2 rounded-lg bg-primary/10">
              <Server class="h-6 w-6 text-primary" />
            </div>
            <div>
              <h2 class="text-2xl font-bold">
                Current Resources: {{ serverData.server.name }}
              </h2>
              <p class="text-sm text-muted-foreground">
                Resources allocated to this server
              </p>
            </div>
          </div>
          <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <MemoryStick class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Memory</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ formatBytes(serverData.server.resources.memory) }}
              </div>
            </div>
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <Cpu class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >CPU</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ formatPercentage(serverData.server.resources.cpu) }}
              </div>
            </div>
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <HardDrive class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Disk</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ formatBytes(serverData.server.resources.disk) }}
              </div>
            </div>
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <Database class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Databases</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ serverData.server.resources.database_limit }}
              </div>
            </div>
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <Archive class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Backups</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ serverData.server.resources.backup_limit }}
              </div>
            </div>
            <div
              class="p-4 rounded-lg bg-card border border-border/50 hover:border-primary/50 transition-colors"
            >
              <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 rounded-md bg-primary/10">
                  <Network class="h-4 w-4 text-primary" />
                </div>
                <Label
                  class="text-xs font-semibold text-muted-foreground uppercase"
                  >Allocations</Label
                >
              </div>
              <div class="text-lg font-bold">
                {{ serverData.server.resources.allocation_limit }}
              </div>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <!-- Edit Dialog -->
    <Dialog :open="showEditDialog" @update:open="closeEditDialog">
      <DialogContent class="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Edit Server Resources</DialogTitle>
          <DialogDescription>
            Update resources for {{ serverData?.server.name }}. You can allocate
            up to your available limits.
          </DialogDescription>
        </DialogHeader>

        <!-- Minimum values warning -->
        <div
          v-if="hasInvalidMinimums"
          class="p-4 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-start gap-3 mb-4"
        >
          <AlertCircle class="h-5 w-5 text-amber-400 shrink-0 mt-0.5" />
          <div class="flex-1">
            <p class="text-sm font-semibold text-amber-400 mb-1">
              Minimum values required
            </p>
            <p class="text-xs text-amber-300/80">
              Memory, CPU, and Disk must be at least 1. Allocation limit must
              be at least 1.
            </p>
          </div>
        </div>

        <!-- Overflow Warning in Dialog -->
        <div
          v-else-if="wouldCauseOverflow"
          class="p-4 rounded-lg bg-red-500/10 border border-red-500/20 flex items-start gap-3 mb-4"
        >
          <AlertCircle class="h-5 w-5 text-red-400 shrink-0 mt-0.5" />
          <div class="flex-1">
            <p class="text-sm font-semibold text-red-400 mb-1">
              Warning: Resource Limit Exceeded
            </p>
            <p class="text-xs text-red-300/80">
              The current values would exceed your resource limits. Please
              adjust the values to stay within your limits.
            </p>
          </div>
        </div>

        <div v-if="serverData" class="space-y-6 py-4">
          <!-- Memory -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <MemoryStick class="h-4 w-4 text-primary" />
              <Label for="memory" class="font-semibold">Memory (MB)</Label>
            </div>
            <Input
              id="memory"
              v-model.number="editForm.memory"
              type="number"
              :min="1"
              :max="serverData.limits.memory_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available: {{ formatBytes(serverData.available.memory_limit) }} /
              Max: {{ formatBytes(serverData.limits.memory_limit) }}
            </p>
          </div>

          <!-- CPU -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <Cpu class="h-4 w-4 text-primary" />
              <Label for="cpu" class="font-semibold">CPU (%)</Label>
            </div>
            <Input
              id="cpu"
              v-model.number="editForm.cpu"
              type="number"
              :min="1"
              :max="serverData.limits.cpu_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available:
              {{ formatPercentage(serverData.available.cpu_limit) }} / Max:
              {{ formatPercentage(serverData.limits.cpu_limit) }}
            </p>
          </div>

          <!-- Disk -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <HardDrive class="h-4 w-4 text-primary" />
              <Label for="disk" class="font-semibold">Disk (MB)</Label>
            </div>
            <Input
              id="disk"
              v-model.number="editForm.disk"
              type="number"
              :min="1"
              :max="serverData.limits.disk_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available: {{ formatBytes(serverData.available.disk_limit) }} /
              Max: {{ formatBytes(serverData.limits.disk_limit) }}
            </p>
          </div>

          <!-- Database Limit -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <Database class="h-4 w-4 text-primary" />
              <Label for="database_limit" class="font-semibold"
                >Database Limit</Label
              >
            </div>
            <Input
              id="database_limit"
              v-model.number="editForm.database_limit"
              type="number"
              :min="0"
              :max="serverData.limits.database_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available: {{ serverData.available.database_limit }} / Max:
              {{ serverData.limits.database_limit }}
            </p>
          </div>

          <!-- Backup Limit -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <Archive class="h-4 w-4 text-primary" />
              <Label for="backup_limit" class="font-semibold"
                >Backup Limit</Label
              >
            </div>
            <Input
              id="backup_limit"
              v-model.number="editForm.backup_limit"
              type="number"
              :min="0"
              :max="serverData.limits.backup_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available: {{ serverData.available.backup_limit }} / Max:
              {{ serverData.limits.backup_limit }}
            </p>
          </div>

          <!-- Allocation Limit -->
          <div class="space-y-2">
            <div class="flex items-center gap-2 mb-2">
              <Network class="h-4 w-4 text-primary" />
              <Label for="allocation_limit" class="font-semibold"
                >Allocation Limit</Label
              >
            </div>
            <Input
              id="allocation_limit"
              v-model.number="editForm.allocation_limit"
              type="number"
              :min="1"
              :max="serverData.limits.allocation_limit || undefined"
              class="w-full"
            />
            <p class="text-xs text-muted-foreground">
              Available: {{ serverData.available.allocation_limit }} / Max:
              {{ serverData.limits.allocation_limit }}
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" @click="closeEditDialog">Cancel</Button>
          <Button
            @click="saveServerResources"
            :disabled="saving || wouldCauseOverflow || hasInvalidMinimums"
          >
            <Loader2 v-if="saving" class="h-4 w-4 mr-2 animate-spin" />
            <Save v-else class="h-4 w-4 mr-2" />
            Save Changes
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
