package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.data.local.*
import ai.triproof.scanner.databinding.*
import ai.triproof.scanner.util.bangla
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.widget.SearchView
import androidx.core.view.isVisible
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.recyclerview.widget.*

private class ById<T : Any>(val id: (T) -> Long, val sameContent: (T, T) -> Boolean) : DiffUtil.ItemCallback<T>() {
    override fun areItemsTheSame(a: T, b: T) = id(a) == id(b)

    override fun areContentsTheSame(a: T, b: T) = sameContent(a, b)
}

class HistoryAdapter(private val open: (Long) -> Unit) :
    ListAdapter<ScanHistoryEntity, HistoryAdapter.Holder>(ById({ it.id }, { a, b -> a == b })) {
    class Holder(val b: ItemScanHistoryBinding) : RecyclerView.ViewHolder(b.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) =
        Holder(ItemScanHistoryBinding.inflate(LayoutInflater.from(parent.context), parent, false))

    override fun onBindViewHolder(holder: Holder, position: Int) {
        val row = getItem(position)
        with(holder.b) {
            serial.text = row.outerSerial
            time.text = date(row.scannedAt)
            offline.setText(offlineText(row.offlineStatus))
            cloud.setText(cloudText(row.cloudStatus))
            cloud.tint(if (cloudAlarm(row.cloudStatus)) R.color.bad else R.color.blue)
            root.setOnClickListener { open(row.id) }
        }
    }
}

class HistoryFragment : Fragment(R.layout.fragment_history) {
    private val model: HistoryModel by viewModels()

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val b = FragmentHistoryBinding.bind(view)
        val adapter = HistoryAdapter { ResultSheet.forRow(it).show(childFragmentManager, "result") }
        b.list.layoutManager = LinearLayoutManager(requireContext())
        b.list.adapter = adapter
        b.list.addOnScrollListener(
            object : RecyclerView.OnScrollListener() {
                override fun onScrolled(rv: RecyclerView, dx: Int, dy: Int) {
                    if (dy > 0 && !rv.canScrollVertically(1)) model.more()
                }
            }
        )
        b.search.setQuery(model.query.value, false)
        b.search.setOnQueryTextListener(
            object : SearchView.OnQueryTextListener {
                override fun onQueryTextSubmit(query: String?) = false

                override fun onQueryTextChange(newText: String?): Boolean {
                    model.query.value = newText.orEmpty()
                    return true
                }
            }
        )
        val filters =
            mapOf(
                R.id.all to "all",
                R.id.pending to "pending",
                R.id.verified to "verified",
                R.id.alerts to "alerts",
            )
        b.filters.check(filters.entries.first { it.value == model.filter.value }.key)
        b.filters.setOnCheckedStateChangeListener { _, ids ->
            model.filter.value = filters[ids.firstOrNull()] ?: "all"
        }
        viewLifecycleOwner.watch(model.rows) {
            adapter.submitList(it)
            b.empty.isVisible = it.isEmpty()
        }
    }
}

class NotificationAdapter :
    ListAdapter<NotificationEntity, NotificationAdapter.Holder>(ById({ it.id }, { a, b -> a == b })) {
    class Holder(val b: ItemNotificationBinding) : RecyclerView.ViewHolder(b.root)

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) =
        Holder(ItemNotificationBinding.inflate(LayoutInflater.from(parent.context), parent, false))

    override fun onBindViewHolder(holder: Holder, position: Int) {
        val n = getItem(position)
        with(holder.b) {
            val bn = root.context.bangla()
            title.text = if (bn) n.titleBn else n.titleEn
            message.text = if (bn) n.messageBn else n.messageEn
            time.text = date(n.createdAt)
            readState.setText(if (n.isRead) R.string.read else R.string.unread)
        }
    }
}

class NotificationsFragment : Fragment(R.layout.fragment_notifications) {
    private val model: InboxModel by viewModels()

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val b = FragmentNotificationsBinding.bind(view)
        val adapter = NotificationAdapter()
        b.list.layoutManager = LinearLayoutManager(requireContext())
        b.list.adapter = adapter
        b.markRead.setOnClickListener { model.markAll() }
        viewLifecycleOwner.watch(model.rows) {
            adapter.submitList(it)
            b.empty.isVisible = it.isEmpty()
            b.markRead.isEnabled = it.any { n -> !n.isRead }
        }
    }
}
