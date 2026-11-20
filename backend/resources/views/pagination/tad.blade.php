@if ($paginator->hasPages())
  <nav class="pager" role="navigation" aria-label="Phân trang">
    <div class="pager-meta">
      @if ($paginator->total() > 0)
        Hiển thị <strong>{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</strong> / <strong>{{ $paginator->total() }}</strong> mục
        <span class="pager-page-tag">· Trang {{ $paginator->currentPage() }}/{{ $paginator->lastPage() }}</span>
      @endif
    </div>

    <ul class="pager-list">
      {{-- Previous Page Link --}}
      @if ($paginator->onFirstPage())
        <li><span class="pager-btn pager-btn--disabled" aria-disabled="true" aria-label="Trang trước">‹ Trước</span></li>
      @else
        <li><a class="pager-btn" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Trang trước">‹ Trước</a></li>
      @endif

      {{-- Pagination Elements --}}
      @foreach ($elements as $element)
        @if (is_string($element))
          <li><span class="pager-ellipsis" aria-disabled="true">{{ $element }}</span></li>
        @endif

        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <li><span class="pager-btn pager-btn--active" aria-current="page">{{ $page }}</span></li>
            @else
              <li><a class="pager-btn" href="{{ $url }}" aria-label="Trang {{ $page }}">{{ $page }}</a></li>
            @endif
          @endforeach
        @endif
      @endforeach

      {{-- Next Page Link --}}
      @if ($paginator->hasMorePages())
        <li><a class="pager-btn" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Trang sau">Sau ›</a></li>
      @else
        <li><span class="pager-btn pager-btn--disabled" aria-disabled="true" aria-label="Trang sau">Sau ›</span></li>
      @endif
    </ul>
  </nav>
@endif
